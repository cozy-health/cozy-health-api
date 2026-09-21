<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateJournalRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['title'=>['sometimes','nullable','string','max:180'],'content'=>['sometimes','required','string','max:50000'],'mood'=>['sometimes','nullable','string','max:100'],'entry_date'=>['sometimes','date']];} }
