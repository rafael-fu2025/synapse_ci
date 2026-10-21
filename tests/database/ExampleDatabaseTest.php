<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Database\Seeds\ExampleSeeder;
use Tests\Support\Models\ExampleModel;

/**
 * @internal
 */
final class ExampleDatabaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    // Refresh only the example schema, never roll back populated application migrations.
    protected $namespace = 'Tests\\Support';
    protected $seed = ExampleSeeder::class;

    protected function regressDatabase(): void
    {
        // CI4 regress(0) rolls back every namespace; this fixture owns only factories.
        if ($this->db->tableExists('factories')) {
            $this->db->table('factories')->truncate();
        }
    }

    public function testModelFindAll(): void
    {
        $model = new ExampleModel();

        // Get every row created by ExampleSeeder
        $objects = $model->findAll();

        // Make sure the count is as expected
        $this->assertCount(3, $objects);
    }

    public function testSoftDeleteLeavesRow(): void
    {
        $model = new ExampleModel();
        $this->setPrivateProperty($model, 'useSoftDeletes', true);
        $this->setPrivateProperty($model, 'tempUseSoftDeletes', true);

        /** @var stdClass $object */
        $object = $model->first();
        $model->delete($object->id);

        // The model should no longer find it
        $this->assertNull($model->find($object->id));

        // ... but it should still be in the database
        $result = $model->builder()->where('id', $object->id)->get()->getResult();

        $this->assertCount(1, $result);
    }
}
