/*
 * Kop rapor per lembaga (PRD-002 §22.5 AC-8c): logo kiri, 4 baris teks di tengah (baris 2 = nama
 * lembaga, paling besar), garis ganda di bawah. Dipakai halaman Format rapor dan halaman cetak.
 */

/** Kop rapor (dipakai juga di halaman cetak). */
export function ReportHeader({
    lines,
    logo,
}: {
    lines: (string | null)[];
    logo: string | null;
}) {
    const [first, second, third, fourth] = lines;

    return (
        <div className="border-b-4 border-double border-black pb-2">
            <div className="grid grid-cols-[4.5rem_minmax(0,1fr)_4.5rem] items-center gap-2">
                <div className="flex justify-center">
                    {logo && (
                        <img
                            src={logo}
                            alt="Logo lembaga"
                            className="max-h-18 max-w-18 object-contain"
                        />
                    )}
                </div>
                <div className="text-center leading-tight">
                    {first && <p className="text-[11pt]">{first}</p>}
                    {second && (
                        <p className="text-[15pt] font-bold uppercase">
                            {second}
                        </p>
                    )}
                    {third && <p className="text-[10pt]">{third}</p>}
                    {fourth && <p className="text-[10pt]">{fourth}</p>}
                </div>
                <div />
            </div>
        </div>
    );
}
