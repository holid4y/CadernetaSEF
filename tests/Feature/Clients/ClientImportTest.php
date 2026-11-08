<?php

declare(strict_types=1);

namespace Tests\Feature\Clients;

use App\Domain\Clients\Actions\ImportClients;
use App\Livewire\Clients\ClientImport;
use App\Models\Client;
use App\Models\User;
use App\Support\SpreadsheetReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ClientImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['CPF', 'BENEFICIADO', 'E-MAIL', 'ENDEREÇO', 'TELEFONE', 'E-MAIL'];

    public function test_excel_files_and_portuguese_csv_files_are_read(): void
    {
        $rows = [['CPF', 'BENEFICIADO'], ['529.982.247-25', 'José Ávila']];
        $utf8 = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($utf8, "\u{FEFF}CPF;BENEFICIADO\r\n529.982.247-25;José Ávila\r\n");
        $windows = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($windows, mb_convert_encoding("CPF;BENEFICIADO\r\n529.982.247-25;José Ávila\r\n", 'Windows-1252', 'UTF-8'));

        $this->assertSame($rows, SpreadsheetReader::read(self::makeXlsx($rows), 'xlsx', 10));
        $this->assertSame($rows, SpreadsheetReader::read($utf8, 'csv', 10));
        $this->assertSame($rows, SpreadsheetReader::read($windows, 'csv', 10));
    }

    public function test_a_cpf_typed_as_a_number_keeps_its_leading_zero(): void
    {
        $path = self::makeXlsx([['CPF', 'BENEFICIADO', 'E-MAIL'], [2345678992, 'Ana', 'ana@example.com'], [4.512398700017E+13, 'Doces', 'doces@example.com']]);
        $rows = SpreadsheetReader::read($path, 'xlsx', 10);

        $this->assertSame(['2345678992', '45123987000170'], [$rows[1][0], $rows[2][0]]);
        $this->assertSame('023.456.789-92', ImportClients::buildInput($rows[0], $rows[1], [0 => 'document', 1 => 'name', 2 => 'email'], '51')['document']);
    }

    public function test_a_sheet_that_unpacks_into_a_huge_file_is_refused(): void
    {
        $path = self::makeXlsx([['A']]);
        $sheet = tempnam(sys_get_temp_dir(), 'sheet');
        $handle = fopen($sheet, 'w');
        fwrite($handle, '<worksheet><sheetData><row><c t="inlineStr"><is><t>');

        for ($chunk = 0; $chunk < 31; $chunk++) {
            fwrite($handle, str_repeat('x', 1_000_000));
        }

        fwrite($handle, '</t></is></c></row></sheetData></worksheet>');
        fclose($handle);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
        $zip->close();

        $this->expectException(RuntimeException::class);

        SpreadsheetReader::read($path, 'xlsx', 10);
    }

    public function test_the_project_spreadsheet_headers_are_recognised(): void
    {
        $this->assertSame(
            ['document', 'name', 'email', 'notes', 'phone', 'email', 'ignore'],
            ImportClients::guessMapping([...self::HEADERS, 'N']),
        );
    }

    public function test_contacts_are_cleaned_up_and_nothing_readable_is_lost(): void
    {
        $mapping = ImportClients::guessMapping(self::HEADERS);

        $oldMobile = ImportClients::buildInput(self::HEADERS, ['529.982.247-25', 'Ana', 'ANA@EXAMPLE.COM ', 'Rua A, 10', '9671-8762', 'ana.outra@example.com'], $mapping, '51');
        $typo = ImportClients::buildInput(self::HEADERS, ['', 'Bia', 'bia@example.com', '', '(51) 99885-828', 'bia@example.com'], $mapping, '51');
        $letters = ImportClients::buildInput(self::HEADERS, ['12.ABC.345/01DE-35', 'Loja', '-', '', '3595-2211', ''], $mapping, '51');

        $this->assertSame(['(51) 99671-8762', 'ana@example.com'], [$oldMobile['phone'], $oldMobile['email']]);
        $this->assertSame("ENDEREÇO: Rua A, 10\nE-MAIL: ana.outra@example.com", $oldMobile['notes']);
        $this->assertSame([null, 'TELEFONE: (51) 99885-828'], [$typo['phone'], $typo['notes']]);
        $this->assertSame(['organization', '12.ABC.345/01DE-35', '(51) 3595-2211', null], [$letters['type'], $letters['document'], $letters['phone'], $letters['email']]);
    }

    public function test_the_preview_and_the_import_agree_row_by_row(): void
    {
        Client::factory()->create(['document' => '529.982.247-25']);
        Client::factory()->create(['name' => 'Sem Documento', 'document' => null]);
        $mapping = ImportClients::guessMapping(self::HEADERS);
        $rows = [
            ['529.982.247-25', 'Já Cadastrada', 'a@example.com', '', '', ''],
            ['', 'Sem Documento', 'b@example.com', '', '', ''],
            ['111.111.111-11', 'CPF Errado', 'c@example.com', '', '', ''],
            ['45.123.987/0001-70', 'Empresa Sem Contato', '', '', '', ''],
            ['45123987000170', 'Empresa Repetida', 'd@example.com', '', '', ''],
            ['', '', '', '', '', ''],
            ['', 'Pessoa Nova', '', '', '(51) 99812-4410', ''],
        ];
        $import = app(ImportClients::class);

        $preview = array_column(array_filter($import->check(self::HEADERS, $rows, $mapping, '51'), fn (array $line): bool => $line['problem'] !== null), 'row');
        $result = $import->handle(self::HEADERS, $rows, $mapping, '51');

        $this->assertSame([2, 3, 4, 6], $preview);
        $this->assertSame($preview, array_column($result['skipped'], 'row'));
        $this->assertSame(2, $result['imported']);
        $this->assertDatabaseHas('clients', ['name' => 'Empresa Sem Contato', 'type' => 'organization', 'phone' => null, 'email' => null, 'accepts_reminders' => false]);
    }

    public function test_who_came_with_an_e_mail_receives_the_messages(): void
    {
        $mapping = ['name', 'email'];

        $this->assertTrue(ImportClients::buildInput(['NOME', 'E-MAIL'], ['Ana', 'ana@example.com'], $mapping, '51')['accepts_reminders']);
        $this->assertFalse(ImportClients::buildInput(['NOME', 'E-MAIL'], ['Ana', ''], $mapping, '51')['accepts_reminders']);
    }

    public function test_the_page_imports_once_and_marks_clients_without_contact(): void
    {
        $this->actingAs(User::factory()->create());
        $file = UploadedFile::fake()->createWithContent('atendidos.csv', "CNPJ;Beneficiado\n45.123.987/0001-70;Doces da Vó\n");

        $page = Livewire::test(ClientImport::class)
            ->set('file', $file)
            ->call('read')
            ->assertHasNoErrors()
            ->assertSee('Importar 1 atendido')
            ->call('import')
            ->assertSee('1 atendidos importados');

        $page->call('import')->assertHasErrors('file');
        $this->assertSame(1, Client::query()->count());
        $this->get(route('clients.index'))->assertSee(__('clients.index.no_contact'));
    }

    public function test_only_people_who_register_clients_can_import(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('clients.import'))->assertForbidden();
        Livewire::test(ClientImport::class)->assertForbidden();
    }

    /**
     * Builds a minimal .xlsx (one sheet, inline strings and numbers) without any package.
     *
     * @param  list<list<string|int|float>>  $rows
     */
    private static function makeXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Planilha1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');

        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach (array_values($rows) as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';

            foreach (array_values($row) as $c => $value) {
                $column = chr(65 + $c);
                $xml .= is_string($value)
                    ? '<c r="'.$column.($r + 1).'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>'
                    : '<c r="'.$column.($r + 1).'"><v>'.$value.'</v></c>';
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        return $path;
    }
}
