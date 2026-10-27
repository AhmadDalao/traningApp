<?php

namespace App\Services;

use App\Models\ExerciseLibraryItem;
use App\Models\TrainingExercise;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExerciseLibrary
{
    private static ?array $builtins = null;

    public function find(?string $key, int $userId): ?array
    {
        if (! $key) {
            return null;
        }
        if (str_starts_with($key, 'builtin:')) {
            self::$builtins ??= collect(json_decode(file_get_contents(resource_path('data/exercise-library.json')), true, flags: JSON_THROW_ON_ERROR))->keyBy('key')->all();

            return self::$builtins[$key] ?? null;
        }
        if (preg_match('/^custom:([1-9][0-9]*)$/', $key, $match)) {
            return ExerciseLibraryItem::where('user_id', $userId)->find($match[1])?->toArray();
        }

        return null;
    }

    public function customItems(int $userId): array
    {
        return ExerciseLibraryItem::where('user_id', $userId)->orderBy('name')->get()->map(fn ($item) => [
            'key' => 'custom:'.$item->id, 'name' => $item->name, 'focus_area' => $item->focus_area,
            'icon' => $item->icon, 'category' => 'My exercises', 'equipment' => 'custom', 'muscles' => [],
            'thumbnail_url' => $item->thumbnail_path ? Storage::disk('public')->url($item->thumbnail_path) : null,
            'custom_icon_url' => $item->custom_icon_path ? Storage::disk('public')->url($item->custom_icon_path) : null,
        ])->all();
    }

    public function copyMedia(?array $source, string $field, string $directory, array &$newPaths): ?string
    {
        if (! $source) {
            return null;
        }
        if ($field === 'thumbnail_path' && isset($source['image'])) {
            $bytes = file_get_contents(public_path('exercise-library/'.$source['image']));
            $extension = 'jpg';
        } elseif (! empty($source[$field])) {
            $bytes = Storage::disk('public')->get($source[$field]);
            $extension = pathinfo($source[$field], PATHINFO_EXTENSION);
        } else {
            return null;
        }
        $path = $directory.'/'.Str::uuid().'.'.$extension;
        $newPaths[] = $path;
        if ($bytes === false || ! Storage::disk('public')->put($path, $bytes)) {
            throw new \RuntimeException('Could not save the exercise image. Please try again.');
        }

        return $path;
    }

    public function saveCustom(TrainingExercise $exercise, int $userId, ?string $focus, array &$newPaths): ExerciseLibraryItem
    {
        return ExerciseLibraryItem::create([
            'user_id' => $userId, 'name' => $exercise->name, 'focus_area' => $focus, 'icon' => $exercise->icon,
            'thumbnail_path' => $this->copyMedia($exercise->toArray(), 'thumbnail_path', 'exercise-library', $newPaths),
            'custom_icon_path' => $this->copyMedia($exercise->toArray(), 'custom_icon_path', 'exercise-library', $newPaths),
        ]);
    }
}
