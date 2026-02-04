<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Interfaces\UserRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {
    }

    /**
     * List users. Provincial staff sees all; municipal admin sees own municipality.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = auth()->user();
        $perPage = min((int) $request->input('per_page', 15), 100);

        // Municipal staff only sees users from their own municipality
        $municipalityId = $user->isProvincialStaff() ? null : $user->municipality_id;

        $users = $this->userRepository->paginate($municipalityId, $perPage);

        return UserResource::collection($users);
    }

    /**
     * Show a single user profile.
     */
    public function show(int $id): JsonResponse
    {
        $authUser = auth()->user();
        $targetUser = $this->userRepository->findById($id);

        if (!$targetUser) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Municipal staff cannot view users from other municipalities
        if ($authUser->isMunicipalStaff() && $targetUser->municipality_id !== $authUser->municipality_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'data' => new UserResource($targetUser),
        ]);
    }

    /**
     * Create a new user.
     * Authorization handled by StoreUserRequest.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userRepository->create($request->validated());

        return response()->json([
            'data' => new UserResource($user->load('municipality')),
            'message' => 'User created successfully.',
        ], 201);
    }

    /**
     * Update an existing user.
     * Authorization handled by UpdateUserRequest.
     */
    public function update(int $id, UpdateUserRequest $request): JsonResponse
    {
        $targetUser = $this->userRepository->findById($id);

        if (!$targetUser) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validated = $request->validated();

        // Remove password if not provided (avoid setting null)
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $updated = $this->userRepository->update($id, $validated);

        return response()->json([
            'data' => new UserResource($updated),
            'message' => 'User updated successfully.',
        ]);
    }

    /**
     * Soft delete a user (admin only, cannot delete self).
     */
    public function destroy(int $id): JsonResponse
    {
        $authUser = auth()->user();

        if (!$authUser->isAdmin()) {
            return response()->json(['message' => 'Unauthorized. Admin access required.'], 403);
        }

        if ($authUser->id === $id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        $targetUser = $this->userRepository->findById($id);

        if (!$targetUser) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Municipal admin cannot delete users from other municipalities
        if ($authUser->isMunicipalStaff() && $targetUser->municipality_id !== $authUser->municipality_id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $this->userRepository->delete($id);

        return response()->json(['message' => 'User deleted successfully.']);
    }
}
