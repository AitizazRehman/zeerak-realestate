<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingDocument extends Model {
 protected $fillable=['booking_id','document_type','name','file_path','file_size','mime_type','uploaded_by','notes'];
 protected $appends=['url'];
 public function booking(){return $this->belongsTo(Booking::class);}
 public function uploader(){return $this->belongsTo(User::class,'uploaded_by');}
 public function getUrlAttribute(){return $this->file_path?asset('storage/'.$this->file_path):null;}
}