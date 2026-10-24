<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Category::class);

        return CategoryResource::collection(Category::query()->where('organization_id', $request->user()->organization_id)->orderBy('name')->get());
    }

    public function store(StoreCategoryRequest $request, AuditLogger $audit): JsonResponse
    {
        $category = Category::query()->create([...$request->validated(), 'organization_id' => $request->user()->organization_id]);
        $audit->record($request, $request->user(), 'category.created', 'category', $category->id);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $categoryId): CategoryResource
    {
        $category = $this->find($request, $categoryId);
        $this->authorize('view', $category);

        return new CategoryResource($category);
    }

    public function update(Request $request, string $categoryId, AuditLogger $audit): CategoryResource
    {
        $category = $this->find($request, $categoryId);
        $this->authorize('update', $category);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:120', Rule::unique('categories', 'name')->where('organization_id', $request->user()->organization_id)->ignore($category->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $category->fill($data)->save();
        $audit->record($request, $request->user(), 'category.updated', 'category', $category->id);

        return new CategoryResource($category);
    }

    public function deactivate(Request $request, string $categoryId, AuditLogger $audit): CategoryResource
    {
        $category = $this->find($request, $categoryId);
        $this->authorize('deactivate', $category);
        $category->forceFill(['active' => false])->save();
        $audit->record($request, $request->user(), 'category.deactivated', 'category', $category->id);

        return new CategoryResource($category);
    }

    private function find(Request $request, string $categoryId): Category
    {
        return Category::query()->where('organization_id', $request->user()->organization_id)->whereKey($categoryId)->firstOrFail();
    }
}
