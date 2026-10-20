<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class ProviderVerifyRequest extends ApiFormRequest { public function authorize():bool{return true;} public function rules():array{return ['code'=>['required','string','size:6']];} }
