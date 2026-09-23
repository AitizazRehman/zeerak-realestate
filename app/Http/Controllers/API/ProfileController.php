<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'data' => $request->user()->load('branch:id,name')
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150'
        ]);

        $request->user()->update($data);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => $request->user()->fresh()->load('branch:id,name')
        ]);
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed'
        ]);

        if (!Hash::check($data['current_password'], $request->user()->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $request->user()->update([
            'password' => Hash::make($data['password'])
        ]);

        $request->user()->tokens()
            ->where('id', '!=', optional($request->user()->currentAccessToken())->id)
            ->delete();

        return response()->json([
            'message' => 'Password changed successfully.'
        ]);
    }

    public function photo(Request $request)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048'
        ]);

        $user = $request->user();
        $oldPath = $user->profile_photo;
        $newPath = $request->file('photo')->store('profile-photos', 'public');

        if (!$newPath) {
            abort(500, 'Profile picture could not be stored.');
        }

        try {
            $user->profile_photo = $newPath;
            $user->save();
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($newPath);
            throw $e;
        }

        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Profile picture updated.',
            'data' => $user->fresh()->load('branch:id,name')
        ]);
    }
}
