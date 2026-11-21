import { Link } from '@inertiajs/react';
import {
    ClipboardList,
    LayoutGrid,
    PackageOpen,
    Package,
    Ruler,
    ShoppingCart,
    Tags,
    Truck,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useRoles } from '@/hooks/use-roles';
import { dashboard } from '@/routes';
import categories from '@/routes/categories';
import goodsReceipts from '@/routes/goods-receipts';
import pos from '@/routes/pos';
import products from '@/routes/products';
import stockOpnames from '@/routes/stock-opnames';
import suppliers from '@/routes/suppliers';
import units from '@/routes/units';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { canSell, canManageStock } = useRoles();

    const groups: { label: string; items: NavItem[] }[] = [
        {
            label: 'Utama',
            items: [
                { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
                ...(canSell
                    ? [
                          {
                              title: 'Kasir',
                              href: pos.index(),
                              icon: ShoppingCart,
                          },
                      ]
                    : []),
            ],
        },
        ...(canManageStock
            ? [
                  {
                      label: 'Inventori',
                      items: [
                          {
                              title: 'Penerimaan barang',
                              href: goodsReceipts.index(),
                              icon: PackageOpen,
                          },
                          {
                              title: 'Stok opname',
                              href: stockOpnames.index(),
                              icon: ClipboardList,
                          },
                      ],
                  },
                  {
                      label: 'Master data',
                      items: [
                          {
                              title: 'Produk',
                              href: products.index(),
                              icon: Package,
                          },
                          {
                              title: 'Kategori',
                              href: categories.index(),
                              icon: Tags,
                          },
                          { title: 'Satuan', href: units.index(), icon: Ruler },
                          {
                              title: 'Supplier',
                              href: suppliers.index(),
                              icon: Truck,
                          },
                      ],
                  },
              ]
            : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {groups.map((group) => (
                    <NavMain
                        key={group.label}
                        label={group.label}
                        items={group.items}
                    />
                ))}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
