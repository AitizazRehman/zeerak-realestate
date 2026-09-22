<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CompanySetting extends Model {
 protected $fillable=['company_name','legal_name','phone','whatsapp','email','website','address','city','ntn','currency','logo_path','receipt_footer'];
 protected $appends=['logo_url'];
 public function getLogoUrlAttribute(){return $this->logo_path?asset('storage/'.$this->logo_path):asset('images/zeerak-logo.jpeg');}
}