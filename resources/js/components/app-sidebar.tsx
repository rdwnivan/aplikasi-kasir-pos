import { Link, usePage } from '@inertiajs/react';
import {
    ChartColumn,
    ClipboardList,
    ClipboardPen,
    LayoutGrid,
    NotebookPen,
    PackageOpen,
    ReceiptText,
    Package,
    Ruler,
    ShoppingCart,
    Tags,
    Truck,
    UsersRound,
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
import customers from '@/routes/customers';
import goodsReceipts from '@/routes/goods-receipts';
import pos from '@/routes/pos';
import products from '@/routes/products';
import purchaseOrders from '@/routes/purchase-orders';
import reports from '@/routes/reports';
import stockOpnames from '@/routes/stock-opnames';
import suppliers from '@/routes/suppliers';
import units from '@/routes/units';
import users from '@/routes/users';
import type { NavItem } from '@/types';

export function AppSidebar() {
    const { canSell, canManageStock, isOwner } = useRoles();
    const { lowStockCount } = usePage().props;

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
                          {
                              title: 'Riwayat transaksi',
                              href: pos.sales.index(),
                              icon: ReceiptText,
                          },
                          {
                              title: 'Pelanggan & kasbon',
                              href: customers.index(),
                              icon: NotebookPen,
                          },
                      ]
                    : []),
            ],
        },
        ...(isOwner
            ? [
                  {
                      label: 'Laporan',
                      items: [
                          {
                              title: 'Laporan',
                              href: reports.sales(),
                              icon: ChartColumn,
                          },
                          {
                              title: 'Pengguna',
                              href: users.index(),
                              icon: UsersRound,
                          },
                      ],
                  },
              ]
            : []),
        ...(canManageStock
            ? [
                  {
                      label: 'Inventori',
                      items: [
                          {
                              title: 'Pesanan pembelian',
                              href: purchaseOrders.index(),
                              icon: ClipboardPen,
                          },
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
                              badge: lowStockCount ?? undefined,
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
