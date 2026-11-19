<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePropertyPhotoRequest;
use App\Http\Resources\V1\PropertyResource;
use App\Models\Property;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class PropertyPhotoController extends Controller
{
    public function store(StorePropertyPhotoRequest $request, Property $property): PropertyResource
    {
        $previous = $property->photo_path;

        $property->update(['photo_path' => $request->file('photo')->store('properties', 'public')]);

        if ($previous !== null) {
            Storage::disk('public')->delete($previous);
        }

        return new PropertyResource($property->load('manager:id,name'));
    }

    public function destroy(Property $property): PropertyResource
    {
        Gate::authorize('update', $property);

        if ($property->photo_path !== null) {
            Storage::disk('public')->delete($property->photo_path);
            $property->update(['photo_path' => null]);
        }

        return new PropertyResource($property->load('manager:id,name'));
    }
}
