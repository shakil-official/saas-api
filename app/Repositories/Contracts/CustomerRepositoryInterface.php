<?php

namespace App\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface CustomerRepositoryInterface
{
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator;

    public function find(int $id): ?Model;

    public function create(array $data): Model;

    public function update(Model $customer, array $data): Model;

    public function delete(Model $customer): bool;
}
