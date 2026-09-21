<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class SubmitQuizRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['answers'=>['required','array','min:1'],'answers.*.question_id'=>['required','integer','distinct','exists:quiz_questions,id'],'answers.*.option_id'=>['required','integer','exists:quiz_options,id']];} }
