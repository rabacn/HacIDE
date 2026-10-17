bits 64
default rel

extern system.linux.output.print

section .data
    msg         dw 14 ; max length header
                db `Hello World\r\n`, 0
    msg1        dw 13 ; max length header
                db `from Roger\r\n`, 0
    tmp         dd 1

section .bss
    tmp1        resd 1

section .text
global _start

_start:
    call main
    mov rax, 60
    xor rdi, rdi
    syscall

global main

main: ; line 16

WHILE_START_0: ; line 17
    movsxd  r10, dword [rel tmp] ; line 17
    cmp     r10, 4 ; line 17
    jge     WHILE_END_1 ; line 17
    lea     rdi, [rel msg + 2] ; line 18
    call    system.linux.output.print ; line 18
    mov     dword [rel tmp1], eax ; line 18
    movsxd  r10, dword [rel tmp] ; line 19
    cmp     r10, 2 ; line 19
    jne     IF_END_2 ; line 19
    lea     rdi, [rel msg1 + 2] ; line 20
    call    system.linux.output.print ; line 20
    mov     dword [rel tmp1], eax ; line 20

IF_END_2: ; line 19
    movsxd  r10, dword [rel tmp] ; line 22
    mov     r11, r10 ; line 22
    add     r11, 1
    mov     dword [rel tmp], r11d ; line 22
    jmp     WHILE_START_0 ; line 17

WHILE_END_1: ; line 17
    ret
