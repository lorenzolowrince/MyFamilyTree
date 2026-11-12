<?php

namespace App\Services\Media;

use App\Support\FuzzyDate\FuzzyDate;
use App\Support\TempFile;
use Imagick;
use ImagickException;
use ImagickPixel;

/**
 * Turns an uploaded image into clean JPEG files.
 *
 * Nothing of the upload is kept byte for byte: the pixels are decoded and written out again,
 * which destroys anything hidden in the file, and all metadata is removed, in particular the
 * GPS position a phone embeds (a photo taken at home would otherwise reveal the address).
 */
class ImageEncoder
{
    /**
     * @param  array<string, int>  $sizes  name => longest edge in pixels
     * @return array{files: array<string, string>, width: int, height: int, taken: FuzzyDate|null}
     *                                                                                             `files` maps each size name to a temporary file the caller must delete
     *
     * @throws MediaRejected when the file is not an image that can be read safely
     */
    public function encode(string $path, array $sizes): array
    {
        $files = [];

        try {
            $probe = new Imagick;
            $probe->pingImage($path.'[0]');

            if ($probe->getImageWidth() * $probe->getImageHeight() > (int) config('media.max_megapixels') * 1_000_000) {
                throw new MediaRejected(__('The image is too large (more than :n megapixels).', ['n' => config('media.max_megapixels')]));
            }

            $probe->clear();

            $image = new Imagick;
            $image->readImage($path.'[0]');

            // Read before the metadata is thrown away: the one detail worth keeping.
            $taken = self::takenDate($image);

            self::orient($image);
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            $image->setImageBackgroundColor(new ImagickPixel('white'));
            $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
            $image->stripImage();
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(88);
            $image->setInterlaceScheme(Imagick::INTERLACE_JPEG);

            $width = $height = 0;

            foreach ($sizes as $name => $edge) {
                $copy = clone $image;

                if ($copy->getImageWidth() > $edge || $copy->getImageHeight() > $edge) {
                    $copy->thumbnailImage($edge, $edge, true);
                }

                if ($name === array_key_first($sizes)) {
                    $width = $copy->getImageWidth();
                    $height = $copy->getImageHeight();
                }

                $target = TempFile::create('mft-img-');
                $files[$name] = $target;
                $copy->writeImage('jpeg:'.$target);
                $copy->clear();
            }

            $image->clear();

            return ['files' => $files, 'width' => $width, 'height' => $height, 'taken' => $taken];
        } catch (ImagickException) {
            array_map(fn (string $file) => @unlink($file), $files);

            throw new MediaRejected(__('The file could not be read as an image.'));
        } catch (MediaRejected $exception) {
            array_map(fn (string $file) => @unlink($file), $files);

            throw $exception;
        }
    }

    /** Turn the pixels the way the camera was held, since the orientation tag is about to go. */
    private static function orient(Imagick $image): void
    {
        $transparent = new ImagickPixel('none');

        match ($image->getImageOrientation()) {
            Imagick::ORIENTATION_TOPRIGHT => $image->flopImage(),
            Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage($transparent, 180),
            Imagick::ORIENTATION_BOTTOMLEFT => $image->flipImage(),
            Imagick::ORIENTATION_LEFTTOP => [$image->rotateImage($transparent, 90), $image->flopImage()],
            Imagick::ORIENTATION_RIGHTTOP => $image->rotateImage($transparent, 90),
            Imagick::ORIENTATION_RIGHTBOTTOM => [$image->rotateImage($transparent, 270), $image->flopImage()],
            Imagick::ORIENTATION_LEFTBOTTOM => $image->rotateImage($transparent, 270),
            default => null,
        };

        $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        $image->setImagePage(0, 0, 0, 0);
    }

    private static function takenDate(Imagick $image): ?FuzzyDate
    {
        try {
            $value = $image->getImageProperty('exif:DateTimeOriginal') ?: $image->getImageProperty('exif:DateTime');
        } catch (ImagickException) {
            return null;
        }

        if (preg_match('/^(\d{4}):(\d{2}):(\d{2})/', $value, $match) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) $match[1], (int) $match[2], (int) $match[3]];

        return $year >= 1826 && $year <= (int) date('Y') && checkdate($month, $day, $year)
            ? FuzzyDate::day($year, $month, $day)
            : null;
    }
}
