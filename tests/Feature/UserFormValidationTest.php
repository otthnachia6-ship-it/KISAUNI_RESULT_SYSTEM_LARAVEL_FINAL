<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\SchoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Add/Edit User must refuse empty usernames, names and passwords,
 * passwords shorter than 4 characters, and classes that do not exist.
 */
class UserFormValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $hm;
    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->class = SchoolClass::orderBy('id')->first();
    }

    private function add(array $overrides)
    {
        return $this->actingAs($this->hm)->from('/users/add')->post('/users/add', array_merge([
            'username' => 'newteacher', 'full_name' => 'New Teacher', 'password' => 'Temp#123',
            'role' => 'class_teacher', 'class_id' => $this->class->id,
        ], $overrides));
    }

    public function test_add_rejects_missing_or_bad_fields(): void
    {
        $before = User::count();
        foreach ([
            ['username' => ''],
            ['username' => '   '],
            ['full_name' => ''],
            ['password' => ''],
            ['password' => 'abc'],
            ['class_id' => 999999],
        ] as $bad) {
            $this->add($bad)->assertRedirect('/users/add')->assertSessionHas('danger');
        }
        $this->assertSame($before, User::count(), 'no account may be created from bad input');
    }

    public function test_add_still_works_with_good_data(): void
    {
        $this->add([])->assertRedirect(route('users'))->assertSessionHas('success');
        $u = User::findByUsername('newteacher');
        $this->assertNotNull($u);
        $this->assertTrue(Hash::check('Temp#123', $u->password));
        $this->assertSame($u->id, (int) $this->class->fresh()->teacher_id);
    }

    public function test_password_is_not_sent_back_to_the_form(): void
    {
        $this->add(['full_name' => '', 'password' => 'Secret#1'])
            ->assertSessionHas('danger')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_edit_rejects_empty_name_short_password_and_unknown_class(): void
    {
        $t = User::create(['username' => 'tt', 'password' => Hash::make('old-pass'), 'full_name' => 'TT',
            'role' => 'class_teacher', 'class_id' => $this->class->id, 'active' => 1, 'must_change_password' => 0,
            'created_at' => SchoolService::nowStr()]);

        foreach ([
            ['full_name' => ''],
            ['password' => 'ab'],
            ['class_id' => 999999],
        ] as $bad) {
            $this->actingAs($this->hm)->post("/users/edit/{$t->id}", array_merge([
                'username' => 'tt', 'full_name' => 'TT', 'role' => 'class_teacher', 'class_id' => $this->class->id, 'password' => '',
            ], $bad))->assertSessionHas('danger');
        }

        $fresh = $t->fresh();
        $this->assertSame('TT', $fresh->full_name);
        $this->assertTrue(Hash::check('old-pass', $fresh->password));
        $this->assertSame($this->class->id, (int) $fresh->class_id);
    }
}
