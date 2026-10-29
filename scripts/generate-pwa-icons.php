<?php

// Render the existing dumbbell brand as opaque, high-resolution install icons.
foreach (['icon-192.png' => 192, 'icon-512.png' => 512, 'maskable-512.png' => 512, 'apple-touch-icon.png' => 180] as $name => $size) {
    $canvas = imagecreatetruecolor(1024, 1024);
    $blue = imagecolorallocate($canvas, 23, 99, 239);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $blue);
    // Keep the whole mark inside the central 80% maskable safe circle.
    $scale = 18;
    $offset = 152;
    $thickness = 50;
    imagesetthickness($canvas, $thickness);
    foreach ([[8, 17, 8, 23], [13, 12, 13, 28], [27, 12, 27, 28], [32, 17, 32, 23], [13, 20, 27, 20]] as [$x1, $y1, $x2, $y2]) {
        $a = $offset + $x1 * $scale;
        $b = $offset + $y1 * $scale;
        $c = $offset + $x2 * $scale;
        $d = $offset + $y2 * $scale;
        imageline($canvas, $a, $b, $c, $d, $white);
        imagefilledellipse($canvas, $a, $b, $thickness, $thickness, $white);
        imagefilledellipse($canvas, $c, $d, $thickness, $thickness, $white);
    }
    $output = imagecreatetruecolor($size, $size);
    imagecopyresampled($output, $canvas, 0, 0, 0, 0, $size, $size, 1024, 1024);
    imagepng($output, __DIR__.'/../public/pwa/'.$name);
}
