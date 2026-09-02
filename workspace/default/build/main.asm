bits 64
default rel

section .data
    hello       dw 12 ; max length header
                db "Hello World", 0
    EOF         dd 32
    tmp         dw 0
    tmp1        dw 3
    my_var      dw 2 ; max length header
                db " ", 0

section .bss
    small_var   resd 1
    cs1         resb 20

section .text
global _start

_start:
    call main
    mov rax, 60
    xor rdi, rdi
    syscall


test: ; line 31
    mov   rax, 34 ; line 32
    ret

main: ; line 35
    mov   byte [rel my_var], 32 ; line 36
    push  rbx ; line 40
    nop    ; line 41
    mov   rbx, 0x3F ; line 42
    mov   eax, dword [rel small_var] ; line 43
    lea   rax, [rel hello] ; line 44
    mov   r10, word [rel cs1 + 4] ; line 45
    shr   r10, 4 ; line 45
    and   r10, 15 ; line 45
    mov   dword [rel small_var], r10d ; line 45
    call  test ; line 46
    mov   word [rel tmp1], ax ; line 46
    mov   r10, word [rel tmp1] ; line 47
    add   r10, 5
    mov   r11, 2 ; line 47
    imul  r11, r10
    mov   dword [rel small_var], r11d ; line 47
    ret
