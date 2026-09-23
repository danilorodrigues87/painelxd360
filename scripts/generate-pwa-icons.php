<?php

/**
 * Gera ícones PWA (192 / 512 / maskable) a partir de resources/assets/img/icons/icone.png
 *
 * Uso: php scripts/generate-pwa-icons.php
 */
$raiz = dirname(__DIR__);
$src = $raiz.'/resources/assets/img/icons/icone.png';
$outDir = $raiz.'/resources/pwa';

if (!is_file($src)) {
	fwrite(STDERR, "Arquivo fonte não encontrado: {$src}\n");
	exit(1);
}

if (!extension_loaded('gd')) {
	fwrite(STDERR, "Extensão GD do PHP não disponível.\n");
	exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
	fwrite(STDERR, "Não foi possível criar: {$outDir}\n");
	exit(1);
}

$img = @imagecreatefrompng($src);
if (!$img) {
	$img = @imagecreatefromstring((string)file_get_contents($src));
}
if (!$img) {
	fwrite(STDERR, "Não foi possível ler a imagem fonte.\n");
	exit(1);
}

imagesavealpha($img, true);

function salvarQuadrado($img, string $dest, int $size): void {
	$w = imagesx($img);
	$h = imagesy($img);
	$canvas = imagecreatetruecolor($size, $size);
	imagealphablending($canvas, false);
	imagesavealpha($canvas, true);
	$transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
	imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);

	$scale = min($size / max(1, $w), $size / max(1, $h));
	$nw = max(1, (int)round($w * $scale));
	$nh = max(1, (int)round($h * $scale));
	$scaled = imagescale($img, $nw, $nh);
	$x = (int)(($size - $nw) / 2);
	$y = (int)(($size - $nh) / 2);
	imagecopy($canvas, $scaled, $x, $y, 0, 0, $nw, $nh);
	imagepng($canvas, $dest);
	imagedestroy($scaled);
	imagedestroy($canvas);
}

function salvarMaskable($img, string $dest, int $size): void {
	$w = imagesx($img);
	$h = imagesy($img);
	$canvas = imagecreatetruecolor($size, $size);
	$bg = imagecolorallocate($canvas, 0, 0, 0);
	imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);

	$inner = (int)round($size * 0.58);
	$scale = min($inner / max(1, $w), $inner / max(1, $h));
	$nw = max(1, (int)round($w * $scale));
	$nh = max(1, (int)round($h * $scale));
	$scaled = imagescale($img, $nw, $nh);
	$x = (int)(($size - $nw) / 2);
	$y = (int)(($size - $nh) / 2);
	imagecopy($canvas, $scaled, $x, $y, 0, 0, $nw, $nh);
	imagepng($canvas, $dest);
	imagedestroy($scaled);
	imagedestroy($canvas);
}

salvarQuadrado($img, $outDir.'/icon-192.png', 192);
salvarQuadrado($img, $outDir.'/icon-512.png', 512);
salvarMaskable($img, $outDir.'/icon-512-maskable.png', 512);
imagedestroy($img);

echo "Ícones PWA gerados em resources/pwa/\n";
echo "Commit: git add resources/pwa/\n";
