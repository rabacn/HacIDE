bits 64
default rel

section .data
    msg         dw 14 ; max length header
                db `Hello World\r\n`, 0
    msg1        dw 13 ; max length header
                db `from Roger\r\n`, 0
    tmp         dd 1

section .bss
    tmp1        resd 1
    p_msg       resq 1

section .text
global _start

_start:
    call main
    mov rax, 60
    xor rdi, rdi
    syscall


print: ; line 12
    mov     qword [rel p_msg], rdi ; line 12
    mov     rax, 1 ; line 13
    mov     rdi, 1 ; line 14
    mov     rsi, qword [rel p_msg] ; line 15
    mov     rdx, 13 ; line 16
    syscall  ; line 17
    ret

main: ; line 20

WHILE_START_0: ; line 21
    movsxd  r10, dword [rel tmp] ; line 21
    cmp     r10, 4 ; line 21
    jge     WHILE_END_1 ; line 21
    lea     rdi, [rel msg + 2] ; line 22
    call    print ; line 22
    mov     dword [rel tmp1], eax ; line 22
    movsxd  r10, dword [rel tmp] ; line 23
    cmp     r10, 2 ; line 23
    jne     IF_END_2 ; line 23
    lea     rdi, [rel msg1 + 2] ; line 24
    call    print ; line 24
    mov     dword [rel tmp1], eax ; line 24

IF_END_2: ; line 23
    movsxd  r10, dword [rel tmp] ; line 26
    mov     r11, r10 ; line 26
    add     r11, 1
    mov     dword [rel tmp], r11d ; line 26
    jmp     WHILE_START_0 ; line 21

WHILE_END_1: ; line 21
    ret
