import {
    Banknote,
    BookOpen,
    Boxes,
    ChartColumn,
    Clock,
    CreditCard,
    DoorOpen,
    Factory,
    FilePlus,
    Handshake,
    Leaf,
    Link,
    Package,
    Plug,
    ScanText,
    ShieldCheck,
    Sparkles,
    UserPlus,
    Users,
    WifiOff,
    Zap,
} from 'lucide-vue-next';

/**
 * Icon names a module manifest may use (menu `icon`, quick action `icon`).
 * Unknown names fall back to a generic box, so a new module never breaks the menu.
 */
const ICONS = {
    banknote: Banknote,
    book: BookOpen,
    chart: ChartColumn,
    clock: Clock,
    'credit-card': CreditCard,
    'door-open': DoorOpen,
    factory: Factory,
    'file-plus': FilePlus,
    handshake: Handshake,
    leaf: Leaf,
    link: Link,
    package: Package,
    plug: Plug,
    scan: ScanText,
    shield: ShieldCheck,
    sparkles: Sparkles,
    'user-plus': UserPlus,
    users: Users,
    'wifi-off': WifiOff,
    zap: Zap,
};

export function moduleIcon(name) {
    return ICONS[name] ?? Boxes;
}
