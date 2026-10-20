<?php

namespace Database\Seeders;

/** Membaca data/sample.json, hasil ekspor dari prototipe lewat data/export-prototype-seed.mjs. */
final class SampleData
{
    /** @return array<string, mixed> */
    public static function load(): array
    {
        return json_decode(file_get_contents(__DIR__.'/data/sample.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
