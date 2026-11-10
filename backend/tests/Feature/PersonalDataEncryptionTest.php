<?php

namespace Tests\Feature;

use App\Models\ClientPartner;
use App\Models\Contact;
use App\Models\User;
use App\Services\Duplicates;
use App\Support\ContactIndex;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\OpenAccessMatrix;
use Tests\TestCase;

/** Email dan telepon kontak tersimpan terenkripsi, tapi cek duplikat tetap jalan lewat indeks HMAC. */
class PersonalDataEncryptionTest extends TestCase
{
    use OpenAccessMatrix, RefreshDatabase;

    public function test_contact_email_and_phone_are_unreadable_in_the_database_but_still_match_duplicates(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->openAccessMatrix();
        $pic = User::factory()->inDepartment('Customer Service')->create();
        $record = ClientPartner::create(['code' => 'CP-ENC', 'name' => 'PT Rahasia', 'category' => 'Lead', 'pic_id' => $pic->id, 'department_id' => $pic->department_id]);
        $contact = Contact::create(['client_partner_id' => $record->id, 'name' => 'Budi', 'email' => 'Budi@Rahasia.co.id', 'phone' => '+62 812-3456-7890', 'is_primary' => true]);

        $row = DB::table('contacts')->where('id', $contact->id)->first();
        $this->assertStringNotContainsString('rahasia', strtolower($row->email));
        $this->assertStringNotContainsString('3456', $row->phone);
        $this->assertSame(ContactIndex::email('budi@rahasia.co.id'), $row->email_hash);
        $this->assertSame('Budi@Rahasia.co.id', $contact->fresh()->email, 'terbaca lagi lewat model');

        $dup = app(Duplicates::class);
        $this->assertSame([(string) $record->id], array_column($dup->check($pic, 'Nama Lain', ' BUDI@rahasia.co.id ')['matches'], 'id'), 'email sama');
        $this->assertSame([(string) $record->id], array_column($dup->check($pic, 'Nama Lain', null, '0812 3456 7890')['matches'], 'id'), 'telepon sama');
        $this->assertSame([], $dup->check($pic, 'Nama Lain', 'orang@lain.id')['matches']);

        $contact->update(['email' => null]);
        $this->assertNull(DB::table('contacts')->where('id', $contact->id)->value('email_hash'));
    }

    public function test_migration_encrypts_existing_plain_contacts(): void
    {
        $migration = require database_path('migrations/2026_11_10_000002_encrypt_personal_data.php');
        $migration->down();
        $this->seed(ReferenceDataSeeder::class);
        $pic = User::factory()->inDepartment('Customer Service')->create();
        $id = DB::table('client_partners')->insertGetId(['code' => 'CP-OLD', 'name' => 'PT Lama', 'category' => 'Lead', 'pic_id' => $pic->id, 'department_id' => $pic->department_id, 'created_at' => now(), 'updated_at' => now()]);
        $cid = DB::table('contacts')->insertGetId(['client_partner_id' => $id, 'name' => 'Sari', 'email' => 'sari@lama.id', 'phone' => '08123456789', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $this->assertNotSame('sari@lama.id', DB::table('contacts')->where('id', $cid)->value('email'));
        $this->assertSame('sari@lama.id', Contact::find($cid)->email);
        $this->assertSame('08123456789', Contact::find($cid)->phone);
        $this->assertSame(ContactIndex::phone('+62 812-3456-789'), DB::table('contacts')->where('id', $cid)->value('phone_hash'));
    }
}
