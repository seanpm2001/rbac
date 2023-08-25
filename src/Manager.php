<?php

declare(strict_types=1);

namespace Yiisoft\Rbac;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Yiisoft\Access\AccessCheckerInterface;
use Yiisoft\Rbac\Exception\DefaultRoleNotFoundException;
use Yiisoft\Rbac\Exception\ItemAlreadyExistsException;

use function array_key_exists;
use function in_array;
use function is_array;

/**
 * An authorization manager that helps with building RBAC hierarchy and check for permissions.
 */
final class Manager implements ManagerInterface
{
    /**
     * @var string[] A list of role names that are assigned to every user automatically without calling {@see assign()}.
     * Note that these roles are applied to users, regardless of their state of authentication.
     */
    private array $defaultRoleNames = [];
    private ?string $guestRoleName = null;

    /**
     * @param ItemsStorageInterface $itemsStorage Items storage.
     * @param AssignmentsStorageInterface $assignmentsStorage Assignments storage.
     * @param RuleFactoryInterface $ruleFactory Rule factory.
     * @param bool $enableDirectPermissions Whether to enable assigning permissions directly to user. Prefer assigning
     * roles only.
     */
    public function __construct(
        private ItemsStorageInterface $itemsStorage,
        private AssignmentsStorageInterface $assignmentsStorage,
        private RuleFactoryInterface $ruleFactory,
        private bool $enableDirectPermissions = false,
    ) {
    }

    public function userHasPermission(
        int|string|Stringable|null $userId,
        string $permissionName,
        array $parameters = [],
    ): bool {
        if ($userId === null) {
            return $this->guestHasPermission($permissionName);
        }

        $userId = (string) $userId;
        $assignments = $this->assignmentsStorage->getByUserId($userId);

        if (empty($assignments)) {
            return false;
        }

        return $this->userHasItem(
            $userId,
            $this->itemsStorage->getPermission($permissionName),
            $parameters,
            $assignments
        );
    }

    public function canAddChild(string $parentName, string $childName): bool
    {
        try {
            $this->assertFutureChild($parentName, $childName);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    public function addChild(string $parentName, string $childName): self
    {
        $this->assertFutureChild($parentName, $childName);
        $this->itemsStorage->addChild($parentName, $childName);

        return $this;
    }

    public function removeChild(string $parentName, string $childName): self
    {
        $this->itemsStorage->removeChild($parentName, $childName);

        return $this;
    }

    public function removeChildren(string $parentName): self
    {
        $this->itemsStorage->removeChildren($parentName);

        return $this;
    }

    public function hasChild(string $parentName, string $childName): bool
    {
        return $this->itemsStorage->hasDirectChild($parentName, $childName);
    }

    public function assign(string $itemName, int|Stringable|string $userId): self
    {
        $userId = (string) $userId;

        $item = $this->itemsStorage->get($itemName);
        if ($item === null) {
            throw new InvalidArgumentException("There is no item named \"$itemName\".");
        }

        if (!$this->enableDirectPermissions && $item->getType() === Item::TYPE_PERMISSION) {
            throw new InvalidArgumentException(
                'Assigning permissions directly is disabled. Prefer assigning roles only.'
            );
        }

        if ($this->assignmentsStorage->get($itemName, $userId) !== null) {
            throw new InvalidArgumentException(
                sprintf('"%s" %s has already been assigned to user %s.', $itemName, $item->getType(), $userId)
            );
        }

        $this->assignmentsStorage->add($itemName, $userId);

        return $this;
    }

    public function revoke(string $itemName, int|Stringable|string $userId): self
    {
        $this->assignmentsStorage->remove($itemName, (string) $userId);

        return $this;
    }

    public function revokeAll(int|Stringable|string $userId): self
    {
        $this->assignmentsStorage->removeByUserId((string) $userId);

        return $this;
    }

    public function getRolesByUserId(int|Stringable|string $userId): array
    {
        $userId = (string) $userId;

        $roles = $this->getDefaultRoles();
        foreach ($this->assignmentsStorage->getByUserId($userId) as $name => $assignment) {
            $role = $this->itemsStorage->getRole($assignment->getItemName());
            if ($role !== null) {
                $roles[$name] = $role;
            }
        }

        return $roles;
    }

    public function getChildRoles(string $roleName): array
    {
        $role = $this->itemsStorage->getRole($roleName);
        if ($role === null) {
            throw new InvalidArgumentException(sprintf('Role "%s" not found.', $roleName));
        }

        return array_merge([$roleName => $role], $this->itemsStorage->getAllChildRoles($roleName));
    }

    public function getPermissionsByRoleName(string $roleName): array
    {
        return $this->itemsStorage->getAllChildPermissions($roleName);
    }

    public function getPermissionsByUserId(int|Stringable|string $userId): array
    {
        $userId = (string) $userId;

        return array_merge(
            $this->getDirectPermissionsByUser($userId),
            $this->getInheritedPermissionsByUser($userId)
        );
    }

    public function getUserIdsByRoleName(string $roleName): array
    {
        $result = [];
        $roles = [$roleName, ...array_keys($this->itemsStorage->getParents($roleName))];

        foreach ($this->assignmentsStorage->getAll() as $userId => $assignments) {
            foreach ($assignments as $userAssignment) {
                if (in_array($userAssignment->getItemName(), $roles, true)) {
                    $result[] = $userId;
                }
            }
        }

        return $result;
    }

    public function addRole(Role $role): self
    {
        $this->addItem($role);
        return $this;
    }

    public function removeRole(string $name): self
    {
        $this->removeItem($name);
        return $this;
    }

    public function updateRole(string $name, Role $role): self
    {
        $this->checkItemNameForUpdate($role, $name);

        $this->itemsStorage->update($name, $role);
        $this->assignmentsStorage->renameItem($name, $role->getName());

        return $this;
    }

    public function addPermission(Permission $permission): self
    {
        $this->addItem($permission);
        return $this;
    }

    public function removePermission(string $permissionName): self
    {
        $this->removeItem($permissionName);
        return $this;
    }

    public function updatePermission(string $name, Permission $permission): self
    {
        $this->checkItemNameForUpdate($permission, $name);

        $this->itemsStorage->update($name, $permission);
        $this->assignmentsStorage->renameItem($name, $permission->getName());

        return $this;
    }

    public function setDefaultRoleNames(Closure|array $roleNames): self
    {
        if (is_array($roleNames)) {
            $this->defaultRoleNames = $roleNames;

            return $this;
        }

        $defaultRoleNames = $roleNames();
        if (!is_array($defaultRoleNames)) {
            throw new RuntimeException('Default role names closure must return an array.');
        }

        /** @var string[] $defaultRoleNames */
        $this->defaultRoleNames = $defaultRoleNames;

        return $this;
    }

    public function getDefaultRoleNames(): array
    {
        return $this->defaultRoleNames;
    }

    public function getDefaultRoles(): array
    {
        $roles = [];
        foreach ($this->defaultRoleNames as $roleName) {
            $role = $this->itemsStorage->getRole($roleName);
            if ($role === null) {
                throw new DefaultRoleNotFoundException("Default role \"$roleName\" not found.");
            }
            $roles[$roleName] = $role;
        }

        return $roles;
    }

    public function setGuestRoleName(?string $name): self
    {
        $this->guestRoleName = $name;
        return $this;
    }

    /**
     * Executes the rule associated with the specified role or permission.
     *
     * If the item does not specify a rule, this method will return `true`. Otherwise, it will
     * return the value of {@see RuleInterface::execute()}.
     *
     * @param string $user The user ID. This should be a string representing the unique identifier of a user.
     * @param Item $item The role or the permission that needs to execute its rule.
     * @param array $params Parameters passed to {@see AccessCheckerInterface::userHasPermission()} and will be passed
     * to the rule.
     *
     * @throws RuntimeException If the role or the permission has an invalid rule.
     *
     * @return bool The return value of {@see RuleInterface::execute()}. If the role or the permission does not specify
     * a rule, `true` will be returned.
     */
    private function executeRule(string $user, Item $item, array $params): bool
    {
        if ($item->getRuleName() === null) {
            return true;
        }

        return $this->ruleFactory
            ->create($item->getRuleName())
            ->execute($user, $item, $params);
    }

    /**
     * @throws ItemAlreadyExistsException
     */
    private function addItem(Item $item): void
    {
        if ($this->itemsStorage->exists($item->getName())) {
            throw new ItemAlreadyExistsException($item);
        }

        $time = time();
        if (!$item->hasCreatedAt()) {
            $item = $item->withCreatedAt($time);
        }
        if (!$item->hasUpdatedAt()) {
            $item = $item->withUpdatedAt($time);
        }

        $this->itemsStorage->add($item);
    }

    private function hasItem(string $name): bool
    {
        return $this->itemsStorage->get($name) !== null;
    }

    /**
     * Returns all permissions that are directly assigned to user.
     *
     * @param string $userId The user ID.
     *
     * @return Permission[] All direct permissions that the user has. The array is indexed by the permission names.
     * @psalm-return array<string,Permission>
     */
    private function getDirectPermissionsByUser(string $userId): array
    {
        $permissions = [];
        foreach ($this->assignmentsStorage->getByUserId($userId) as $name => $assignment) {
            $permission = $this->itemsStorage->getPermission($assignment->getItemName());
            if ($permission !== null) {
                $permissions[$name] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * Returns all permissions that the user inherits from the roles assigned to him.
     *
     * @param string $userId The user ID.
     *
     * @return Permission[] All inherited permissions that the user has. The array is indexed by the permission names.
     */
    private function getInheritedPermissionsByUser(string $userId): array
    {
        $assignments = $this->assignmentsStorage->getByUserId($userId);
        $result = [];
        foreach (array_keys($assignments) as $roleName) {
            $result = array_merge($result, $this->itemsStorage->getAllChildPermissions($roleName));
        }

        return $result;
    }

    private function removeItem(string $name): void
    {
        if ($this->hasItem($name)) {
            $this->itemsStorage->remove($name);
            $this->assignmentsStorage->removeByItemName($name);
        }
    }

    /**
     * Performs access check for the specified user.
     *
     * @param string $user The user ID. This should be a string representing the unique identifier of a user.
     * @param Item|null $item The permission or the role that need access check.
     * @param array $params Name-value pairs that would be passed to rules associated with the permissions and roles
     * assigned to the user. A param with name 'user' is added to this array, which holds the value of `$userId`.
     * @param Assignment[] $assignments The assignments to the specified user.
     *
     * @throws RuntimeException
     *
     * @return bool Whether the operations can be performed by the user.
     */
    private function userHasItem(
        string $user,
        ?Item $item,
        array $params,
        array $assignments
    ): bool {
        if ($item === null) {
            return false;
        }

        if (!$this->executeRule($user, $item, $params)) {
            return false;
        }

        if (array_key_exists($item->getName(), $assignments)) {
            return true;
        }

        foreach ($this->itemsStorage->getParents($item->getName()) as $parentName => $_parent) {
            if (array_key_exists($parentName, $assignments)) {
                return true;
            }
        }

        return false;
    }

    private function guestHasPermission(string $permissionName): bool
    {
        if ($this->guestRoleName === null) {
            return false;
        }

        if ($this->itemsStorage->getRole($this->guestRoleName) === null) {
            return false;
        }

        return $this->itemsStorage->hasDirectChild($this->guestRoleName, $permissionName);
    }

    /**
     * @throws RuntimeException
     */
    private function assertFutureChild(string $parentName, string $childName): void
    {
        $parent = $this->itemsStorage->get($parentName);
        if ($parent === null) {
            throw new RuntimeException("Parent \"$parentName\" does not exist.");
        }

        $child = $this->itemsStorage->get($childName);
        if ($child === null) {
            throw new RuntimeException("Child \"$childName\" does not exist.");
        }

        if ($parentName === $childName) {
            throw new RuntimeException("Cannot add \"$parentName\" as a child of itself.");
        }

        if ($parent->getType() === Item::TYPE_PERMISSION && $child->getType() === Item::TYPE_ROLE) {
            throw new RuntimeException(
                "Can not add \"$childName\" role as a child of \"$parentName\" permission.",
            );
        }

        if ($this->itemsStorage->hasDirectChild($parentName, $childName)) {
            throw new RuntimeException("The item \"$parentName\" already has a child \"$childName\".");
        }

        if ($this->itemsStorage->hasChild($childName, $parentName)) {
            throw new RuntimeException(
                "Cannot add \"$childName\" as a child of \"$parentName\". A loop has been detected.",
            );
        }
    }

    private function checkItemNameForUpdate(Item $item, string $name): void
    {
        if ($item->getName() === $name || !$this->hasItem($item->getName())) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf(
                'Unable to change the role or the permission name. ' .
                'The name "%s" is already used by another role or permission.',
                $item->getName()
            )
        );
    }
}
