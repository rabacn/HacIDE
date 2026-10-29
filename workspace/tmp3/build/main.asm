bits 64
default rel

section .data
    hello       dw 12 ; max length header
                db `Hello World`, 0
    EOF         dd 32

section .bss
    small_var   resd 1
    cs1         resb 6

section .text
global _start

_start:
    call main
    mov rax, 60
    xor rdi, rdi
    syscall


main: ; line 16
    push    rbx ; line 17
    nop      ; line 18
    mov     rbx, 0x3F ; line 19
    lea     rax, [rel hello + 2] ; line 21
    mov     dword [rel cs1], 32 ; line 22
    movsxd  r10, dword [rel cs1] ; line 23
    mov     r11, r10 ; line 23
    add     r11, 3
    mov     r10, 2 ; line 23
    imul    r10, r11
    mov     word [rel cs1 + 4], r10w ; line 23
    ret
