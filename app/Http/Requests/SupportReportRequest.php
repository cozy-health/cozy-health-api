<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class SupportReportRequest extends ApiFormRequest { public function authorize():bool{return true;} public function rules():array{return ['subject'=>['required','string','max:180'],'message'=>['required','string','max:10000']];} }
