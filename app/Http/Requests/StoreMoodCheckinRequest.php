<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreMoodCheckinRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['feeling_exp_id'=>['required','integer','exists:feeling_expressions,id'],'intensity'=>['required','integer','between:1,10'],'reason_ids'=>['nullable','array'],'reason_ids.*'=>['integer','distinct','exists:feeling_causes,id'],'coping_mechanism_ids'=>['nullable','array'],'coping_mechanism_ids.*'=>['integer','distinct','exists:coping_mechanisms,id'],'journal'=>['nullable','string','max:10000']];} }
