<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\CompanySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
class CompanySettingController extends Controller {
 use ChecksBranchAccess;
 private function ensureSettingsAdministrator(){abort_unless($this->canAccessAllBranches(),403,'Only administrators can modify company settings.');}
 private function setting(){return CompanySetting::firstOrCreate([],['company_name'=>'ZeeraK Real Estate & Builders','currency'=>'PKR']);}
 public function show(){return response()->json($this->setting());}
 public function update(Request $request){
  $this->ensureSettingsAdministrator();
  $data=$request->validate(['company_name'=>'required|string|max:255','legal_name'=>'nullable|string|max:255','phone'=>'nullable|string|max:50','whatsapp'=>'nullable|string|max:50','email'=>'nullable|email|max:255','website'=>'nullable|string|max:255','address'=>'nullable|string|max:1000','city'=>'nullable|string|max:100','ntn'=>'nullable|string|max:100','currency'=>'required|string|max:10','receipt_footer'=>'nullable|string|max:1000']);
  $s=$this->setting();$s->update($data);return response()->json(['message'=>'Company settings updated successfully.','settings'=>$s->fresh()]);
 }
 public function logo(Request $request){
  $this->ensureSettingsAdministrator();
  $request->validate(['logo'=>'required|image|mimes:jpg,jpeg,png,webp|max:5120']);$s=$this->setting();
  if($s->logo_path)Storage::disk('public')->delete($s->logo_path);
  $s->logo_path=$request->file('logo')->store('company','public');$s->save();
  return response()->json(['message'=>'Company logo updated successfully.','settings'=>$s->fresh()]);
 }
}