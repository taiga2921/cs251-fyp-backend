<?php

$roots = array_values(array_filter(array_map(
    static fn (string $path): string => trim($path),
    explode(',', (string) env('ANPR_IMAGE_ROOTS', ''))
)));

return [
  /*
    |--------------------------------------------------------------------------
    | ANPR evidence image roots
    |--------------------------------------------------------------------------
    |
    | Comma-separated allow-list of directories the authenticated image file
    | endpoint may read evidence files from. Defaults to storage/app/anpr.
    |
    */
    'image_roots' => $roots !== [] ? $roots : [storage_path('app/anpr')],
];
