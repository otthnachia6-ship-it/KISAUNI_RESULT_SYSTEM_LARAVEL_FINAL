<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * User/Account CRUD parity with Flask: /users, /users/add, /users/edit/<id>,
 * /users/toggle/<id>, /users/delete/<id>. Also guards the GET-vs-POST dispatch
 * bug: a GET on a combined GET/POST route must render the form, never mutate.
 */
class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    protected $hm;
    protected $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $this->hm = User::where('role', 'headmaster')->first();
        $this->hm->update(['must_change_password' => 0]);
        $this->class = SchoolClass::orderBy('sort_order')->first();
    }

    public function test_get_forms_render_and_never_create_users(): void
    {
        $before = User::count();
        $r = $this->actingAs($this->hm)->get('/users/add');
        $r->assertStatus(200)->assertSee('_token', false)->assertSee('name="username"', false);
        $this->assertSame($before, User::count(), 'GET /users/add must not create a user');

        $t = User::create(['username' => 'edit_me', 'password' => Hash::make('x'), 'full_name' => 'Edit Me',
            'role' => 'class_teacher', 'class_id' => null, 'active' => 1, 'must_change_password' => 0, 'created_at' => now()->toDateTimeString()]);
        $this->get("/users/edit/{$t->id}")->assertStatus(200)->assertSee('edit_me', false)->assertSee('Edit Me', false);
        $this->assertSame($before + 1, User::count());
    }

    public function test_create_class_teacher_syncs_class_and_logs(): void
    {
        $r = $this->actingAs($this->hm)->post('/users/add', [
            'username' => 'newteacher', 'full_name' => 'New Teacher', 'password' => 'Temp#123',
            'role' => 'class_teacher', 'class_id' => $this->class->id,
        ]);
        $r->assertRedirect(route('users'));
        $u = User::where('username', 'newteacher')->first();
        $this->assertNotNull($u);
        $this->assertSame('class_teacher', $u->role);
        $this->assertEquals($this->class->id, $u->class_id);
        $this->assertEquals(1, $u->must_change_password);
        $this->assertEquals(1, $u->active);
        $this->assertTrue(Hash::check('Temp#123', $u->password));
        $this->assertEquals($u->id, DB::table('classes')->where('id', $this->class->id)->value('teacher_id'));
        $this->assertTrue(DB::table('audit_log')->where('action', 'ADD_USER')->exists());
    }

    public function test_create_administrator_is_headmaster_role_with_title_and_no_class(): void
    {
        $this->actingAs($this->hm)->post('/users/add', [
            'username' => 'admin2', 'full_name' => 'Admin Two', 'password' => 'Temp#123',
            'role' => 'administrator', 'class_id' => $this->class->id,
        ])->assertRedirect(route('users'));
        $u = User::where('username', 'admin2')->first();
        $this->assertSame('headmaster', $u->role);
        $this->assertSame('Administrator', $u->title);
        $this->assertNull($u->class_id);
    }

    public function test_duplicate_username_is_rejected(): void
    {
        $r = $this->actingAs($this->hm)->from('/users/add')->post('/users/add', [
            'username' => 'headmaster', 'full_name' => 'Dup', 'password' => 'x', 'role' => 'class_teacher',
        ]);
        $r->assertRedirect('/users/add')->assertSessionHas('danger');
        $this->assertSame(1, User::where('username', 'headmaster')->count());
    }

    public function test_edit_updates_fields_password_and_class_sync(): void
    {
        $t = User::create(['username' => 'tt', 'password' => Hash::make('old'), 'full_name' => 'TT',
            'role' => 'class_teacher', 'class_id' => $this->class->id, 'active' => 1, 'must_change_password' => 0,
            'created_at' => now()->toDateTimeString()]);
        DB::table('classes')->where('id', $this->class->id)->update(['teacher_id' => $t->id]);
        $other = SchoolClass::orderBy('sort_order')->skip(1)->first();

        $this->actingAs($this->hm)->post("/users/edit/{$t->id}", [
            'username' => 'tt_renamed', 'full_name' => 'TT Renamed', 'role' => 'class_teacher',
            'class_id' => $other->id, 'password' => 'Brand#New1',
        ])->assertRedirect(route('users'));

        $t->refresh();
        $this->assertSame('tt_renamed', $t->username);
        $this->assertEquals($other->id, $t->class_id);
        $this->assertTrue(Hash::check('Brand#New1', $t->password));
        $this->assertEquals(1, $t->must_change_password);
        $this->assertNull(DB::table('classes')->where('id', $this->class->id)->value('teacher_id'));
        $this->assertEquals($t->id, DB::table('classes')->where('id', $other->id)->value('teacher_id'));
        $this->assertTrue(DB::table('audit_log')->where('action', 'EDIT_USER')->exists());
    }

    public function test_edit_blank_password_keeps_old_and_duplicate_username_rejected(): void
    {
        $t = User::create(['username' => 'keep', 'password' => Hash::make('keepme'), 'full_name' => 'Keep',
            'role' => 'class_teacher', 'active' => 1, 'must_change_password' => 0, 'created_at' => now()->toDateTimeString()]);
        $this->actingAs($this->hm)->post("/users/edit/{$t->id}", [
            'username' => 'keep', 'full_name' => 'Keep 2', 'role' => 'class_teacher', 'password' => '',
        ])->assertRedirect(route('users'));
        $t->refresh();
        $this->assertTrue(Hash::check('keepme', $t->password));
        $this->assertEquals(0, $t->must_change_password);

        $this->actingAs($this->hm)->from("/users/edit/{$t->id}")->post("/users/edit/{$t->id}", [
            'username' => 'headmaster', 'full_name' => 'X', 'role' => 'class_teacher',
        ])->assertRedirect("/users/edit/{$t->id}")->assertSessionHas('danger');
    }

    public function test_toggle_and_delete_rules(): void
    {
        $t = User::create(['username' => 'tog', 'password' => Hash::make('x'), 'full_name' => 'Tog',
            'role' => 'class_teacher', 'class_id' => $this->class->id, 'active' => 1, 'must_change_password' => 0,
            'created_at' => now()->toDateTimeString()]);
        DB::table('classes')->where('id', $this->class->id)->update(['teacher_id' => $t->id]);

        $this->actingAs($this->hm)->post("/users/toggle/{$t->id}")->assertRedirect(route('users'));
        $this->assertEquals(0, $t->fresh()->active);
        $this->post("/users/toggle/{$t->id}");
        $this->assertEquals(1, $t->fresh()->active);

        // headmaster can be neither deactivated nor deleted, and nobody deletes themselves
        $this->post("/users/toggle/{$this->hm->id}")->assertSessionHas('warning');
        $this->assertEquals(1, $this->hm->fresh()->active);
        $this->post("/users/delete/{$this->hm->id}")->assertSessionHas('danger');
        $this->assertNotNull(User::find($this->hm->id));

        $this->post("/users/delete/{$t->id}")->assertRedirect(route('users'));
        $this->assertNull(User::find($t->id));
        $this->assertNull(DB::table('classes')->where('id', $this->class->id)->value('teacher_id'));
        foreach (['TOGGLE_USER', 'DELETE_USER'] as $a) {
            $this->assertTrue(DB::table('audit_log')->where('action', $a)->exists(), $a);
        }
    }

    public function test_class_teacher_cannot_reach_user_management(): void
    {
        $t = User::create(['username' => 'ct', 'password' => Hash::make('x'), 'full_name' => 'CT',
            'role' => 'class_teacher', 'class_id' => $this->class->id, 'active' => 1, 'must_change_password' => 0,
            'created_at' => now()->toDateTimeString()]);
        $this->actingAs($t);
        foreach (['/users', '/users/add', "/users/edit/{$this->hm->id}"] as $p) {
            $this->assertNotSame(200, $this->get($p)->getStatusCode(), "GET $p should be blocked");
        }
        $before = User::count();
        $this->post('/users/add', ['username' => 'evil', 'full_name' => 'E', 'password' => 'x', 'role' => 'headmaster']);
        $this->post("/users/edit/{$this->hm->id}", ['username' => 'hacked', 'full_name' => 'H', 'role' => 'class_teacher']);
        $this->post("/users/delete/{$this->hm->id}");
        $this->assertSame($before, User::count());
        $this->assertSame('headmaster', $this->hm->fresh()->username);
    }
}
