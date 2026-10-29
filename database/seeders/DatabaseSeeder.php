<?php
namespace Database\Seeders; use App\Models\Quiz; use Illuminate\Database\Seeder;
class DatabaseSeeder extends Seeder { public function run():void {
$this->call(AffirmationSeeder::class);
if (app()->environment(['staging', 'demo'])) {
    $this->call(DemoAccountSeeder::class);
}
$q=Quiz::updateOrCreate(['slug'=>'wellbeing-check'],['title'=>'Wellbeing Check','type'=>'wellbeing','description'=>'A short self-reflection check.','estimated_minutes'=>3,'is_active'=>true]);$questions=['How often have you felt able to relax?','How often have you felt hopeful?','How often have you had enough energy for daily activities?'];foreach($questions as $i=>$text){$qq=$q->questions()->updateOrCreate(['sort_order'=>$i+1],['question'=>$text]);foreach([['Never',0],['Sometimes',1],['Often',2],['Nearly always',3]] as $j=>$opt)$qq->options()->updateOrCreate(['sort_order'=>$j+1],['label'=>$opt[0],'value'=>(string)$j,'score'=>$opt[1]]);}
$this->call(PlanCatalogSeeder::class);
}}
