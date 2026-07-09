<?php

namespace App\Constants;

/**
 * Managed resource types reported by Platform Agent plugins.
 */
final class AgentResourceType
{
    public const LOCAL_HOST = 'local_host';

    public const VIRTUAL_MACHINE = 'virtual_machine';

    public const DOCKER_CONTAINER = 'docker_container';

    public const KUBERNETES_NODE = 'kubernetes_node';

    public const HYPERV_VM = 'hyperv_vm';

    public const VMWARE_VM = 'vmware_vm';

    public const NETWORK_DEVICE = 'network_device';

    public const DATABASE = 'database';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::LOCAL_HOST,
            self::VIRTUAL_MACHINE,
            self::DOCKER_CONTAINER,
            self::KUBERNETES_NODE,
            self::HYPERV_VM,
            self::VMWARE_VM,
            self::NETWORK_DEVICE,
            self::DATABASE,
        ];
    }
}
