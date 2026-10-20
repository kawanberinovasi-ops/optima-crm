<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Paket frontend untuk deploy. Di Laravel Cloud hanya folder backend/ yang di-deploy, jadi index.html dan assets/
 * dari root repo disalin ke dalam backend/ saat build:
 * - assets/   -> public/assets/ (menggantikan symlink pengembangan)
 * - index.html -> resources/frontend/index.html, dengan ?v=<hash isi file> di setiap aset supaya browser
 *   tidak memakai JS atau CSS lama setelah deploy.
 * Jalankan sebagai bagian build command, bukan di laptop pengembangan (symlink public/assets akan diganti).
 */
class BuildFrontend extends Command
{
    protected $signature = 'optima:build-frontend
        {--from= : Folder sumber berisi index.html dan assets/ (default: root repo)}
        {--public= : Folder public tujuan (default: public/)}
        {--view= : Folder tujuan index.html (default: resources/frontend)}';

    protected $description = 'Salin frontend (index.html dan assets/) ke dalam aplikasi Laravel untuk deploy';

    public function handle(): int
    {
        $from = rtrim($this->option('from') ?: base_path('..'), '/');
        if (! is_file($from.'/index.html') || ! is_dir($from.'/assets')) {
            $this->error('index.html atau assets/ tidak ditemukan di '.$from);

            return self::FAILURE;
        }

        $target = rtrim($this->option('public') ?: public_path(), '/').'/assets';
        $viewDir = rtrim($this->option('view') ?: resource_path('frontend'), '/');
        if (is_link($target)) {
            unlink($target);
        } elseif (is_dir($target)) {
            File::deleteDirectory($target);
        }
        File::copyDirectory($from.'/assets', $target);

        $html = preg_replace_callback('/(src|href)="assets\/([^"?#]+)"/', function ($m) use ($target) {
            $file = $target.'/'.$m[2];

            return is_file($file) ? $m[1].'="assets/'.$m[2].'?v='.substr(md5_file($file), 0, 10).'"' : $m[0];
        }, file_get_contents($from.'/index.html'));

        File::ensureDirectoryExists($viewDir);
        file_put_contents($viewDir.'/index.html', $html);

        $this->info('Frontend disalin: '.count(File::allFiles($target)).' file aset, index.html di '.$viewDir.'.');

        return self::SUCCESS;
    }
}
