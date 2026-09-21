<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreJournalRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['title'=>['nullable','string','max:180'],'content'=>['required','string','max:50000'],'mood'=>['nullable','string','max:100'],'entry_date'=>['nullable','date']];} }
