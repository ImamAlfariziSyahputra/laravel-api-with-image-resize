<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\ImageManipulation;
use App\Http\Requests\ResizeImageRequest;
use App\Http\Resources\V1\ImageManipulationResource;
use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class ImageManipulationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        return ImageManipulationResource::collection(ImageManipulation::where(['user_id' => $request->user()->id])->paginate());
    }

    public function byAlbum(Request $request, Album $album)
    {
        if ($request->user()->id != $album->user_id) {
            return abort(403, 'Unauthorized');
        }

        $where = [
            'album_id' => $album->id
        ];

        return ImageManipulationResource::collection(ImageManipulation::where($where)->paginate());
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\ResizeImageRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function resize(ResizeImageRequest $request)
    {
        $all = $request->all();

        //** @var UploadedFile|string $image */
        $image = $all['image'];
        unset($all['image']);

        $data = [
            'type' => ImageManipulation::TYPE_RESIZE,
            'data' => json_encode($all),
            'user_id' => $request->user()->id,
        ];

        if (!empty($all['album_id'])) {
            $album = Album::find($all['album_id']);

            if ($request->user()->id != $album->user_id) {
                return abort(403, 'Unauthorized');
            }

            $data['album_id'] = $all['album_id'];
        }

        $dir = 'images/' . Str::random() . '/';
        $absolutePath = public_path($dir); // public/images/random/
        File::makeDirectory($absolutePath);


        if ($image instanceof UploadedFile) { // images
            $data['name'] = $image->getClientOriginalName();
            $fileName = pathinfo($data['name'], PATHINFO_FILENAME);
            $extension = $image->getClientOriginalExtension();
            $originalPath = $absolutePath . $data['name'];

            $image->move($absolutePath, $data['name']);
        } else { // URL
            $data['name'] = pathinfo($image, PATHINFO_BASENAME);
            $fileName = pathinfo($image, PATHINFO_FILENAME);
            $extension = pathinfo($image, PATHINFO_EXTENSION);
            $originalPath = $absolutePath . $data['name'];

            copy($image, $originalPath);
        }

        $data['path'] = $dir . $data['name'];

        $w = $all['w'];
        $h = $all['h'] ?? false;

        list($width, $height, $image) = $this->getImageWidthAndHeight($w, $h, $originalPath);

        $resizedFileName = $fileName . '-resized.' . $extension;

        //! Save Resized File to the Folder
        $image->resize($width, $height)->save($absolutePath . $resizedFileName);
        $data['output_path'] = $dir . $resizedFileName;

        $imageManipultaion = ImageManipulation::create($data);

        return new ImageManipulationResource($imageManipultaion);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\ImageManipulation  $imageManipulation
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, ImageManipulation $image)
    {
        if ($request->user()->id != $image->user_id) {
            return abort(403, 'Unauthorized');
        }

        return new ImageManipulationResource($image);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\ImageManipulation  $imageManipulation
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, ImageManipulation $image)
    {
        if ($request->user()->id != $image->user_id) {
            return abort(403, 'Unauthorized');
        }

        $image->delete();

        return response('', 204);
    }

    protected function getImageWidthAndHeight($w, $h, $originalPath)
    {
        $image = Image::make($originalPath);
        $originalWidth = $image->width();
        $originalHeight = $image->height();

        if (str_ends_with($w, '%')) { // Percent
            $ratioW = (float)str_replace('%', '', $w);
            $ratioH = $h ? (float)str_replace('%', '', $h) : $ratioW;

            //! Calculate original w and h by percent ex:(100px * 50% = 50px)
            $newWidth = $originalWidth * $ratioW / 100;
            $newHeight = $originalHeight * $ratioH / 100;
        } else { // Number
            $newWidth = (float)$w;
            /**
             * $originalWidth - $newWidth
             * $originalHeight - $newHeight
             * ----------------------------
             * $newHeight = $originalHeight * $newWidth / $originalWidth
             */

            $newHeight = $h ? (float)$h : $originalHeight * $newWidth / $originalWidth;
        }

        return [$newWidth, $newHeight, $image];
    }
}
