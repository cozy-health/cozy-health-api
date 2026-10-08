<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class RegisterRequest extends ApiFormRequest { public function authorize():bool{return true;} public function rules():array{return ['name'=>['required','string','max:120'],'email'=>['required','email','max:190','unique:users,email'],'password'=>['required','string','min:8','confirmed'],'date_of_birth'=>['nullable','date','before:today'],'gender'=>['nullable','string','max:40'],'personalization'=>['nullable','array']];} }
