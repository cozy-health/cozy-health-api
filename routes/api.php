<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\{AuthController,PasswordResetController,ProfileController,LookupController,MoodCheckinController,JournalController,QuizController,ActivityController,NotificationController,ProviderController,SubscriptionController,SupportController};
Route::prefix('v1')->group(function(){
 Route::post('register',[AuthController::class,'register']); Route::post('login',[AuthController::class,'login']);
 Route::post('forgot-password',[PasswordResetController::class,'forgotPassword']);
 Route::post('reset-password',[PasswordResetController::class,'resetPassword']);
 Route::middleware('auth:sanctum')->group(function(){
  Route::post('logout',[AuthController::class,'logout']); Route::get('me',[ProfileController::class,'show']); Route::put('me',[ProfileController::class,'update']);
  Route::get('feelings',[LookupController::class,'feelings']); Route::get('feeling-expressions',[LookupController::class,'expressions']); Route::get('feeling-causes',[LookupController::class,'causes']); Route::get('coping-mechanisms',[LookupController::class,'coping']);
  Route::get('mood-checkins/today',[MoodCheckinController::class,'today']); Route::apiResource('mood-checkins',MoodCheckinController::class)->only(['index','store']);
  Route::apiResource('journals',JournalController::class)->only(['index','store','update','destroy']);
  Route::get('quizzes',[QuizController::class,'index']); Route::get('quizzes/{quiz}',[QuizController::class,'show']); Route::post('quizzes/{quiz}/submit',[QuizController::class,'submit']); Route::get('quiz-results',[QuizController::class,'results']);
  Route::get('activity/overview',[ActivityController::class,'overview']); Route::get('activity/mood-chart',[ActivityController::class,'moodChart']); Route::get('activity/common-triggers',[ActivityController::class,'triggers']); Route::get('activity/journal-stats',[ActivityController::class,'journalStats']); Route::get('activity/recommendations',[ActivityController::class,'recommendations']);
  Route::get('notifications',[NotificationController::class,'index']); Route::get('notifications/unread-count',[NotificationController::class,'unread']); Route::post('notifications/read-all',[NotificationController::class,'readAll']); Route::post('notifications/{id}/read',[NotificationController::class,'read']); Route::delete('notifications/{id}',[NotificationController::class,'destroy']);
  Route::get('provider',[ProviderController::class,'show']); Route::post('provider/request',[ProviderController::class,'requestCode']); Route::post('provider/verify',[ProviderController::class,'verify']); Route::delete('provider',[ProviderController::class,'destroy']);
  Route::get('subscription',[SubscriptionController::class,'show']); Route::get('subscription/packages',[SubscriptionController::class,'packages']); Route::post('subscription/activate',[SubscriptionController::class,'activate']); Route::post('subscription/cancel',[SubscriptionController::class,'cancel']);
  Route::post('support/report-problem',[SupportController::class,'store']); Route::get('support/my-reports',[SupportController::class,'index']);
 });
});
