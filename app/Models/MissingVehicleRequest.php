<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MissingVehicleRequest extends Model { protected $fillable = ['first_name','last_name','email','phone','province','brand','model','year','comment','photo_path']; }
