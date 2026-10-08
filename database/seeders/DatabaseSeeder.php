<?php
namespace Database\Seeders; use App\Models\{Quiz,SubscriptionPackage}; use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder { public function run():void {
$this->call(AffirmationSeeder::class);
if (app()->environment(['staging', 'demo'])) {
    $this->call(DemoAccountSeeder::class);
}
$q=Quiz::updateOrCreate(['slug'=>'wellbeing-check'],['title'=>'Wellbeing Check','type'=>'wellbeing','description'=>'A short self-reflection check.','estimated_minutes'=>3,'is_active'=>true]);$questions=['How often have you felt able to relax?','How often have you felt hopeful?','How often have you had enough energy for daily activities?'];foreach($questions as $i=>$text){$qq=$q->questions()->updateOrCreate(['sort_order'=>$i+1],['question'=>$text]);foreach([['Never',0],['Sometimes',1],['Often',2],['Nearly always',3]] as $j=>$opt)$qq->options()->updateOrCreate(['sort_order'=>$j+1],['label'=>$opt[0],'value'=>(string)$j,'score'=>$opt[1]]);}
SubscriptionPackage::updateOrCreate(['slug'=>'free'],['package_name'=>'Free','description'=>'Core Cozy Health access.','price'=>0,'currency'=>'NGN','duration'=>'monthly','duration_days'=>30,'features'=>['Mood check-ins','Journals','Quizzes'],'is_active'=>true,'sort_order'=>1]);SubscriptionPackage::updateOrCreate(['slug'=>'premium'],['package_name'=>'Premium','description'=>'Complete Cozy Health access.','price'=>5000,'currency'=>'NGN','duration'=>'monthly','duration_days'=>30,'features'=>['Everything in Free','Extended analytics','Provider connection'],'is_active'=>true,'sort_order'=>2]);
}}
