import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

// Tombol (UX-2, gaya dasbor Materio): huruf kapital kecil berjarak, sudut 6px, tinggi 40px; utama ungu dengan bayangan
// ungu, garis = garis & teks ungu, teks = tanpa latar. Fokus = cincin ungu 2px.
const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium uppercase tracking-[0.03em] transition-[color,background-color,box-shadow] disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-primary hover:bg-primary-active active:bg-primary-active",
        destructive:
          "border border-destructive/60 bg-transparent text-destructive hover:bg-destructive/10",
        outline:
          "border border-primary/60 bg-transparent text-primary hover:bg-accent dark:text-accent-foreground",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/70",
        ghost: "text-primary hover:bg-accent dark:text-accent-foreground",
        link: "normal-case tracking-normal text-primary underline-offset-4 hover:underline dark:text-accent-foreground",
      },
      size: {
        default: "h-10 px-5 has-[>svg]:px-4",
        sm: "h-8 px-3.5 text-xs has-[>svg]:px-3",
        lg: "h-12 px-7 has-[>svg]:px-6",
        icon: "size-9 rounded-full normal-case",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
