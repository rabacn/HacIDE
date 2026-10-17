bits 64
default rel

section .bss
    p_msg       resq 1

section .text
global system.linux.output.print

system.linux.output.print: ; line 4
    mov     qword [rel p_msg], rdi ; line 4
    mov     rax, 1 ; line 5
    mov     rdi, 1 ; line 6
    mov     rsi, qword [rel p_msg] ; line 7
    mov     rdx, 13 ; line 8
    syscall  ; line 9
    ret
