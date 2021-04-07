<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Posts\Media;
use App\Repositories\MediaRepository;
use App\Repositories\MediaRepositoryEloquent;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\File\File;

class MediaService
{
    /** @var MediaRepositoryEloquent */
    private $mediaRepository;

    /** @var FFMpeg */
    private $ffmpeg;

    /** @var bool */
    private $convertToJpeg = false;

    /**
     * MediaService constructor.
     *
     * @param MediaRepository $mediaRepository
     * @param FFMpeg          $ffmpeg
     */
    public function __construct(MediaRepository $mediaRepository, FFMpeg $ffmpeg)
    {
        $this->mediaRepository = $mediaRepository;
        $this->ffmpeg = $ffmpeg;
    }

    /**
     * Create new media file instance from uploaded file.
     *
     * @param UploadedFile $file
     *
     * @return Media
     *
     * @throws \Prettus\Validator\Exceptions\ValidatorException
     */
    public function createFromFile(UploadedFile $file): Media
    {
        $type = $this->typeByMime($file->getMimeType());

        $hash = sha1_file($file->getRealPath());

        if ($this->mediaRepository->findWhere(['hash' => $hash])->count()) {
            throw new \RuntimeException('File already exists.');
        }

        $src = \ImageUtils::storeMediaImage($file, $this->convertToJpeg);

        $media = $this->mediaRepository->create([
            'hash' => $hash,
            'type' => $type,
            'role' => Media::ROLE_FILE,
            'src' => $src,
            'mime' => $file->getMimeType(),
            'additions' => $this->getAdditions($file, $type),
        ]);

        return $media;
    }

    /**
     * Rename media files.
     *
     * @param Media  $media
     * @param string $name
     */
    public function renameMedia(Media &$media, string $name)
    {
        $media->src = \ImageUtils::renameFile($media->src, $name);

        if (Media::TYPE_VIDEO == $media->type && $media->addition('preview')) {
            $additions = $media->additions;
            $additions['preview'] = \ImageUtils::renameFile((string) $media->addition('preview'), $name);
            $media->additions = $additions;
        }
    }

    /**
     * Get default additions for media file.
     *
     * @param File $file
     * @param int  $type
     *
     * @return array
     */
    public function getAdditions(File $file, int $type): array
    {
        switch ($type) {
            case Media::TYPE_IMAGE:
                return $this->getImageAdditions($file);
            case Media::TYPE_VIDEO:
                return $this->getVideoAdditions($file);
            case Media::TYPE_AUDIO:
                return $this->getAudioAdditions($file);
        }

        throw new \RuntimeException('Unknown media type.');
    }

    /**
     * Default additions for image file.
     *
     * @param File $file
     *
     * @return array
     */
    private function getImageAdditions(File $file): array
    {
        $sizes = getimagesize($file->getRealPath());

        return [
            'width' => $sizes[0],
            'height' => $sizes[1],
            'alt' => '',
            'title' => '',
        ];
    }

    /**
     * Default additions for video file.
     *
     * @param File $file
     *
     * @return array
     */
    private function getVideoAdditions(File $file): array
    {
        $filePath = $file->getRealPath();
        $video = $this->ffmpeg->open($filePath);
        $frame = $video->frame(TimeCode::fromSeconds(2));

        $basename = basename($filePath);
        list($hash, $ext) = explode('.', $basename);
        $framePath = str_replace($basename, $hash.'.jpg', $filePath);

        $frame->save($framePath);

        $stream = $this->ffmpeg->getFFProbe()->streams($filePath)->first();

        return [
            'duration' => (float) $stream->get('duration'),
            'width' => $stream->get('width'),
            'height' => $stream->get('height'),
            'preview' => str_replace(storage_path('app/public'), '', $framePath),
        ];
    }

    /**
     * Default additions for audio file.
     *
     * @param File $file
     *
     * @return array
     */
    private function getAudioAdditions(File $file): array
    {
        $filePath = $file->getRealPath();

        $audio = $this->ffmpeg->open($filePath);

        return [
            'duration' => $audio->getFormat()->get('duration'),
        ];
    }

    /**
     * Detect file type by mime.
     *
     * @param string $mime
     *
     * @return int
     */
    public function typeByMime(string $mime): int
    {
        list($base, $_) = explode('/', $mime);
        $typeMap = [
            'audio' => Media::TYPE_AUDIO,
            'image' => Media::TYPE_IMAGE,
            'video' => Media::TYPE_VIDEO,
        ];

        if (! isset($typeMap[$base])) {
            throw new \RuntimeException('Unknown file type.');
        }

        return $typeMap[$base];
    }

    /**
     * @param Media $media
     *
     * @throws \Exception
     */
    public function safeDelete(Media $media)
    {
        $images = $this->mediaRepository->findWhere(['parent_id' => $media->id]);
        \DB::beginTransaction();
        try {
            /** @var Media $image */
            foreach ($images as $image) {
                if (Media::ROLE_FOLDER === $image->role) {
                    throw new \RuntimeException('Can\'t delete this folder. The folder contains sub folder.');
                }
                $this->mediaRepository->update(['parent_id' => null], $image->id);
            }
            \DB::commit();

            $media->delete();
        } catch (\RuntimeException $e) {
            \Log::warning($e->getMessage());
            \DB::rollBack();

            throw $e;
        }
    }

    /**
     * @param bool $convertToJpeg
     *
     * @return MediaService
     */
    public function setConvertToJpeg(bool $convertToJpeg): self
    {
        $this->convertToJpeg = $convertToJpeg;

        return $this;
    }
}
