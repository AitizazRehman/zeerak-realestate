<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
class ProfileController extends Controller {
 public function show(Request $r){return response()->json(['data'=>$r->user()->load('branch:id,name')]);}
 public function update(Request $r){$d=$r->validate(['name'=>'required|string|max:150']);$r->user()->update($d);return response()->json(['message'=>'Profile updated successfully.','data'=>$r->user()->fresh()->load('branch:id,name')]);}
 public function password(Request $r){$d=$r->validate(['current_password'=>'required|string','password'=>'required|string|min:8|confirmed']);if(!Hash::check($d['current_password'],$r->user()->password))return response()->json(['message'=>'Current password is incorrect.'],422);$r->user()->update(['password'=>Hash::make($d['password'])]);$r->user()->tokens()->where('id','!=',optional($r->user()->currentAccessToken())->id)->delete();return response()->json(['message'=>'Password changed successfully.']);}
 public function photo(Request $r){$r->validate(['photo'=>'required|image|mimes:jpg,jpeg,png,webp|max:2048']);$u=$r->user();if($u->profile_photo)Storage::disk('public')->delete($u->profile_photo);$u->profile_photo=$r->file('photo')->store('profile-photos','public');$u->save();return response()->json(['message'=>'Profile picture updated.','data'=>$u->fresh()]);}
}