<?php

namespace App\Services\Media;

/**
 * What the MediaPresenter has looked up while answering one request. Registered as a scoped
 * service and fetched from the container at each use, so nothing remembered for one request
 * can be served to the next (controllers, and whatever they were built with, can outlive a
 * request).
 */
class MediaMemo
{
    /** @var array<string, string> family id => slug */
    public array $slugs = [];

    /** @var array<string, array<string, string>> family id => [profile photo id => visibility] */
    public array $profilePhotos = [];
}
