<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class ActivateSubscriptionRequest extends ApiFormRequest { public function authorize():bool{return true;} public function rules():array{return ['package_id'=>['required','integer','exists:subscription_packages,id']];} }
