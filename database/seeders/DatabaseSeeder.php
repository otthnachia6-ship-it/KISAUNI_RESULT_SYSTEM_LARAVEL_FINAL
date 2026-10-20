<?php

namespace Database\Seeders;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $nowStr = SchoolService::nowStr();

        // 1. Seed classes with standard and stream
        if (SchoolClass::count() === 0) {
            $sortOrder = 1;
            for ($std = 1; $std <= SchoolService::NUM_STANDARDS; $std++) {
                $word = SchoolService::ORDINAL_WORDS[$std];
                $streams = SchoolService::DEFAULT_STREAMS[$std] ?? ['A'];
                foreach ($streams as $stream) {
                    $name = "Standard {$word} {$stream}";
                    SchoolClass::create([
                        'name' => $name,
                        'standard' => $std,
                        'stream' => $stream,
                        'sort_order' => $sortOrder++,
                        'teacher_id' => null,
                        'last_promoted_year' => SchoolService::DEFAULT_ACADEMIC_YEAR,
                    ]);
                }
            }
        }

        // 2. Seed subjects
        $allSubjectNames = array_values(array_unique(array_merge(
            SchoolService::LOWER_CLASS_SUBJECTS,
            SchoolService::UPPER_CLASS_SUBJECTS
        )));

        $subjectMap = [];
        foreach ($allSubjectNames as $sname) {
            $sub = Subject::firstOrCreate(['name' => $sname]);
            $subjectMap[$sname] = $sub->id;
        }

        // 3. Seed class_subjects mapping
        $classes = SchoolClass::all();
        foreach ($classes as $cls) {
            $subjectList = SchoolService::subjectsForStandard($cls->standard);
            foreach ($subjectList as $sname) {
                if (isset($subjectMap[$sname])) {
                    DB::table('class_subjects')->insertOrIgnore([
                        'class_id' => $cls->id,
                        'subject_id' => $subjectMap[$sname],
                    ]);
                }
            }
        }

        // 4. Seed examinations
        if (Examination::count() === 0) {
            foreach (SchoolService::EXAM_TYPES as $etype) {
                Examination::firstOrCreate([
                    'exam_type' => $etype,
                    'academic_year' => SchoolService::DEFAULT_ACADEMIC_YEAR,
                ], [
                    'created_at' => $nowStr,
                ]);
            }
        }

        // 5. Seed default headmaster
        if (User::where('role', SchoolService::ROLE_HEADMASTER)->count() === 0) {
            User::create([
                'username' => 'headmaster',
                'password' => Hash::make('admin123'),
                'full_name' => 'Head Master',
                'role' => SchoolService::ROLE_HEADMASTER,
                'active' => 1,
                'must_change_password' => 1,
                'created_at' => $nowStr,
            ]);
        }

        // 6. Seed default settings
        $defaults = [
            'school_name' => SchoolService::DEFAULT_SCHOOL_NAME,
            'academic_year' => SchoolService::DEFAULT_ACADEMIC_YEAR,
            'logo_path' => 'images/logo.png',
            'classes_streams_migrated' => '1',
        ];

        foreach ($defaults as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
    }
}
