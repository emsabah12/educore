/**
 * Tata letak halaman cetak (mis. rapor, PRD-002 §19 AC-5d): tanpa sidebar & kepala aplikasi, latar
 * putih di layar maupun kertas, apa pun tema yang dipilih pengguna.
 */
export default function PrintLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    return (
        <div className="min-h-svh bg-white text-black print:min-h-0">
            {children}
        </div>
    );
}
