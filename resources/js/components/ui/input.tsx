import * as React from "react"

import { cn } from "@/lib/utils"

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return (
    <input
      type={type}
      data-slot="input"
      className={cn(
        // UX-1 (DESIGN-airbnb text-input): 48px, sudut 8px, garis abu; fokus = garis tinta 2px tanpa cahaya.
        "border-input file:text-foreground placeholder:text-muted-foreground selection:bg-foreground selection:text-background flex h-12 w-full min-w-0 rounded-md border bg-background px-3 py-1 text-base transition-colors outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60 md:text-sm",
        "focus-visible:border-foreground focus-visible:ring-1 focus-visible:ring-foreground",
        "aria-invalid:border-destructive aria-invalid:ring-1 aria-invalid:ring-destructive",
        className
      )}
      {...props}
    />
  )
}

export { Input }
