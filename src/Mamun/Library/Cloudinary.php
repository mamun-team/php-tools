<?php

namespace App\Library;

use App\Library\Mamun;
use Illuminate\Support\Facades\Storage;

class Cloudinary
{

    private $folder;
    private $utility;

    public function __construct($folder)
    {
        $this->folder = $folder;
        $this->utility = Mamun::Utility();
    }

    public function upload($file, $folder = null, $new_name = null)
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        
        $baseName = $new_name ? pathinfo($new_name, PATHINFO_FILENAME) : (uniqid() . '_' . pathinfo($originalName, PATHINFO_FILENAME));

        $safeName = $this->utility->clean($baseName, 'file_name');        
        $filename = strtolower($safeName . '.' . $extension);
        
        $path = ($folder ?? $this->folder) . '/' . $filename;

        if (Storage::disk('s3')->exists($path)) {
            Storage::disk('s3')->delete($path);
        }

        $stored = Storage::disk('s3')->put($path, file_get_contents($file), 'public');

        if (!$stored) return null;

        return [
            'filename' => $filename,
            'path' => $path,
            'url' => Storage::disk('s3')->url($path),
        ];
    }

    public function delete($path)
    {
        return Storage::disk('s3')->delete($path);
    }
}
