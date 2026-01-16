<?php
require_once __DIR__ . '/db.php';

function processThermalImage($inputPath, $outputPath, $maxWidth = 200) {
    // Batasi maks lebar jadi 200px biar nggak kegedean di struk
    $image = @imagecreatefromstring(file_get_contents($inputPath));
    if (!$image) return false;

    $origWidth = imagesx($image);
    $origHeight = imagesy($image);

    if ($origWidth > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int)($origHeight * ($newWidth / $origWidth));
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($image);
        $image = $resized;
    }

    $bw = imagecreatetruecolor(imagesx($image), imagesy($image));
    // Set background putih bersih
    $white = imagecolorallocate($bw, 255, 255, 255);
    imagefill($bw, 0, 0, $white);

    // Konversi ke Hitam Putih murni (Thresholding)
    for ($x = 0; $x < imagesx($image); $x++) {
        for ($y = 0; $y < imagesy($image); $y++) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            
            // Rumus luminance standar
            $gray = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
            
            // Threshold keras: di bawah 128 jadi hitam, di atas jadi putih
            $bwColor = ($gray < 128) ? 0 : 255;
            
            $color = imagecolorallocate($bw, $bwColor, $bwColor, $bwColor);
            imagesetpixel($bw, $x, $y, $color);
        }
    }

    $result = imagepng($bw, $outputPath);
    imagedestroy($image);
    imagedestroy($bw);
    return $result;
}

function generateQRBase64($text, $size = 5) {
    // Placeholder QR
    $data = 'iVBORw0KGgoAAAANSUhEUgAAAGQAAABkCAYAAABw4pVUAAAABmJLR0QA/wD/AP+gvaeTAAAFJElEQVR4nO3dW27bMBBF0ciL/v9P248CQdE4liwN74MgQNpDcmhKJgAAAAAAAAAAAAAAAAAAAAAAAAAAwJjO2QfA770/53x+3t7fP/7+4+/n57ye8zpX+/fZx2e+qj2QbV6zXt+q3y99v9XvV79f+n6r369+v/r90vfDmKoNZJvXrNe36vdL32/1+6Xvt/r96ver369+v/T9MCaHZNZ83m/1+6Xvt/r90vdb/X71+9XvV79f+n4Yk0Mya16zXt+q3y99v9Xvl77f6ver369+v/r90vfDmBySWfOZffX7pe+3+r3S91v9fvX71e9Xv1/6fhiTQzJrPrOvfr/0/ar3q96ver/q/ar3q94vfT+MySGZNa9Zr2/V75e+3+r3S99v9ful77f6/er3q9+vfr/6/dL3w5gcklnzmnXL96ver3q/6v2q96ver36/9P0wJqdk1nymX/1+6ftV71e9X/V+1ftV71e9X/p+GJNDMms+s69+v/T9qver3q96v+r9qver3i99P4zJIZk1n9lXv1/6ftX7Ve9XvV/1ftX7Ve+Xvh/G5JDMms/sq98vfb/q/ar3q96ver/q/ar3S98PY3JIZs1n9tXvl75f9X7V+1XvV71f9X7V+6XvhzE5JLPmM/vq90vfr3q/6v2q96ver3q/6v3S98OYHJJZ85l99ful71e9X/V+1ftV71e9X/V+6fthTA7JrPnMvvr90ver3q96v+r9qver3q96v/T9MCaHZNZ8Zl/9fun7Ve9XvV/1ftX7Ve9XvV/6fhiTQzJrPrOvfr/0/ar3q96ver/q/ar3q94vfT+MySGZNa9Zr2/V75e+3+r3S99v9ful77f6/er3q9+vfr/6/dL3w5gcklnzmnXL96ver3q/6v2q96ver36/9P0wJqdk1nymX/1+6ftV71e9X/V+1ftV71e9X/p+GJNDMms+s69+v/T9qver3q96v+r9qver3i99P4zJIZk1n9lXv1/6ftX7Ve9XvV/1ftX7Ve+Xvh/G5JDMms/sq98vfb/q/ar3q96ver/q/ar3S98PY3JIZs1n9tXvl75f9X7V+1XvV71f9X7V+6XvhzE5JLPmM/vq90vfr3q/6v2q96ver3q/6v3S98OYHJJZ85l99ful71e9X/V+1ftV71e9X/V+6fthTA7JrPnMvvr90ver3q96v+r9qver3q96v/T9MCaHZNZ8Zl/9fun7Ve9XvV/1ftX7Ve9XvV/6fhiTQzJrXrNe36rfL32/1e+Xvt/q90vfb/X71e9Xv1/9fvX7pe+HMVUbSI1r1utb9ful77f6/dL3W/1+6futfr/6/er3q9+vfr/0/TAmyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zXt+r3S99v9ful77f6/dL3W/1+9fvV71e/X/1+6fthTA7JrHnNes16fat+v/T9Vr9f+n6r3y99v9XvV79f/X71+9Xvl74fxuSQzJrXrNes17fq90vfb/X7pe+3+v3S91v9fvX71e9Xv1/9fun7YUwOyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zXt+r3S99v9ful77f6/dL3W/1+9fvV71e/X/1+6fthTA7JrHnNes16fat+v/T9Vr9f+n6r3y99v9XvV79f/X71+9Xvl74fxuSQzJrXrNes17fq90vfb/X7pe+3+v3S91v9fvX71e9Xv1/9fun7YUwOyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zXt+r3S99v9ful77f6/dL3W/1+9fvV71e/X/1+6fthTA7JrHnNes16fat+v/T9Vr9f+n6r3y99v9XvV79f/X71+9Xvl74fxuSQzJrXrNes17fq90vfb/X7pe+3+v3S91v9fvX71e9Xv1/9fun7YUwOyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zXt+r3S99v9ful77f6/dL3W/1+9fvV71e/X/1+6fthTA7JrHnNes16fat+v/T9Vr9f+n6r3y99v9XvV79f/X71+9Xvl74fxuSQzJrXrNes17fq90vfb/X7pe+3+v3S91v9fvX71e9Xv1/9fun7YUwOyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zXt+r3S99v9ful77f6/dL3W/1+9fvV71e/X/1+6fthTA7JrHnNes16fat+v/T9Vr9f+n6r3y99v9XvV79f/X71+9Xvl74fxuSQzJrXrNes17fq90vfb/X7pe+3+v3S91v9fvX71e9Xv1/9fun7YUwOyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zXt+r3S99v9ful77f6/dL3W/1+9fvV71e/X/1+6fthTA7JrHnNes16fat+v/T9Vr9f+n6r3y99v9XvV79f/X71+9Xvl74fxuSQzJrXrNes17fq90vfb/X7pe+3+v3S91v9fvX71e9Xv1/9fun7YUwOyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zXt+r3S99v9ful77f6/dL3W/1+9fvV71e/X/1+6fthTA7JrHnNes16fat+v/T9Vr9f+n6r3y99v9XvV79f/X71+9Xvl74fxuSQzJrXrNes17fq90vfb/X7pe+3+v3S91v9fvX71e9Xv1/9fun7YUwOyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zXt+r3S99v9ful77f6/dL3W/1+9fvV71e/X/1+6fthTA7JrHnNes16fat+v/T9Vr9f+n6r3y99v9XvV79f/X71+9Xvl74fxuSQzJrXrNes17fq90vfb/X7pe+3+v3S91v9fvX71e9Xv1/9fun7YUwOyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zXt+r3S99v9ful77f6/dL3W/1+9fvV71e/X/1+6fthTA7JrHnNes16fat+v/T9Vr9f+n6r3y99v9XvV79f/X71+9Xvl74fxuSQzJrXrNes17fq90vfb/X7pe+3+v3S91v9fvX71e9Xv1/9fun7YUwOyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zXt+r3S99v9ful77f6/dL3W/1+9fvV71e/X/1+6fthTA7JrHnNes16fat+v/T9Vr9f+n6r3y99v9XvV79f/X71+9Xvl74fxuSQzJrXrNes17fq90vfb/X7pe+3+v3S91v9fvX71e9Xv1/9fun7YUwOyax5zXp9q36/9P1Wv1/6fqvfL32/1e9Xv1/9fvX71e+Xvh/G5JDMmtes16zNc3jKnaQGrMs17fqt8vfb/V75e+3+r3S99v9fvV71e/X/1+9ful74cxOSSz5jXrNev1rfr90vdb/X7p+61+v/T9Vr9f/X71+9XvV79f+n4Yk0Mya16zXrNe36rfL32/1e+Xvt/q90vfb/X71e9Xv1/9fvX7pe+HMf0Hb8GQe+0XxN8AAAAASUVORK5CYII=';
    return 'image/png;base64,' . $data;
}